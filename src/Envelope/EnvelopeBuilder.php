<?php

declare(strict_types=1);

namespace Nowo\Beacon\Envelope;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer;
use Nowo\Beacon\Context\DatabaseExceptionContext;
use Nowo\Beacon\Context\UserContextProviderInterface;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Exception\EnvelopeEncodingException;
use Nowo\Beacon\Request\RequestContextProviderInterface;
use Nowo\Beacon\Request\RequestSnapshot;
use Nowo\Beacon\Scope\Scope;
use Nowo\Beacon\Scrubber\FrameVarsSerializer;
use Nowo\Beacon\Scrubber\SensitiveValueRedactor;
use Nowo\Beacon\Trace\TraceIdProvider;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_reverse;
use function array_values;
use function bin2hex;
use function count;
use function debug_backtrace;
use function dirname;
use function file;
use function filesize;
use function is_array;
use function is_file;
use function is_int;
use function is_readable;
use function is_string;
use function max;
use function min;
use function php_uname;
use function random_bytes;
use function str_contains;
use function str_replace;
use function str_starts_with;

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const DIRECTORY_SEPARATOR;
use const FILE_IGNORE_NEW_LINES;
use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * Builds single-item Envelopes for Beacon ingest.
 *
 * Output is byte-for-byte identical to BeaconBundle 1.19's `EnvelopeBuilder` for the same input when the header DSN
 * mode is {@see EnvelopeDsnMode::Full} (golden tests in `tests/Golden`), so the bundle can delegate to this class.
 * The default mode ({@see EnvelopeDsnMode::None}) leaves the DSN out of the body: the secret then only travels in the
 * `X-Beacon-Auth` header.
 *
 * Framework specifics are injected: the current request ({@see RequestContextProviderInterface}), the framework
 * context (`['name' => 'symfony', 'version' => '8.1.0']`), the throwable used for the culprit (Messenger's
 * `HandlerFailedException` → root cause) and extra implementation frames to hide from message stack traces.
 *
 * The source-line cache is per instance and bounded (LRU of {@see SOURCE_LINE_CACHE_MAX_FILES} files ≤ 1 MiB), so a
 * long-running worker does not grow.
 */
final class EnvelopeBuilder
{
    public const SOURCE_LINE_CACHE_MAX_FILES = 64;

    public const MAX_SOURCE_FILE_BYTES = 1_048_576;

    /** Request headers copied into `request.headers` (allow-list; everything else is never sent). */
    public const REQUEST_HEADERS = [
        'host',
        'user-agent',
        'accept',
        'accept-language',
        'accept-encoding',
        'content-type',
        'origin',
        'referer',
        'x-requested-with',
    ];

    /** @var array<string, list<string>|null> */
    private array $sourceLineCache = [];

    private readonly ClockInterface $clock;

    /** @var Closure(): string */
    private readonly Closure $eventIdGenerator;

    /**
     * @param array{name: string, version: string}|null $framework contexts.framework (sent when `send.framework`)
     * @param (Closure(Throwable): Throwable)|null $culpritThrowableResolver which throwable names the culprit
     * @param list<string> $internalClassPrefixes extra class prefixes hidden from message stack traces
     * @param list<string> $internalPaths extra path prefixes hidden from message stack traces
     * @param (Closure(): string)|null $eventIdGenerator 32-hex id generator (tests / golden envelopes)
     */
    public function __construct(
        private readonly string $environment = 'production',
        private readonly ?string $release = null,
        private readonly string $serverName = 'unknown',
        private readonly SendOptions $sendOptions = new SendOptions(),
        private readonly ?UserContextProviderInterface $userContextProvider = null,
        private readonly ?BreadcrumbBuffer $breadcrumbBuffer = null,
        private readonly ?RequestContextProviderInterface $requestProvider = null,
        private readonly int $stackContextLines = 5,
        private readonly ?Scope $scope = null,
        private readonly ?TraceIdProvider $traceIdProvider = null,
        ?ClockInterface $clock = null,
        private readonly EnvelopeDsnMode $dsnMode = EnvelopeDsnMode::None,
        private readonly ?array $framework = null,
        private readonly ?Closure $culpritThrowableResolver = null,
        private readonly array $internalClassPrefixes = [],
        private readonly array $internalPaths = [],
        ?Closure $eventIdGenerator = null,
    ) {
        $this->clock = $clock ?? new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
        $this->eventIdGenerator = $eventIdGenerator ?? static fn (): string => bin2hex(random_bytes(16));
    }

    /**
     * Event (message and/or exception) envelope.
     *
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     */
    public function eventEnvelope(
        Dsn $dsn,
        string $message,
        string $level = 'error',
        ?Throwable $throwable = null,
        array $extra = [],
        ?array $fingerprint = null,
    ): Envelope {
        $eventId    = ($this->eventIdGenerator)();
        $occurredAt = $this->clock->now();
        $sentAt     = $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $timestamp  = (float) $occurredAt->format('U.u');

        $payload = [
            'event_id'  => $eventId,
            'timestamp' => $timestamp,
            'datetime'  => $sentAt,
            'platform'  => 'php',
            'level'     => $level,
            'logger'    => 'nowo.beacon',
            'message'   => SensitiveValueRedactor::redactMessage($message),
        ];

        if ($this->sendOptions->serverName) {
            $payload['server_name'] = $this->serverName;
        }

        if ($this->sendOptions->environment) {
            $payload['environment'] = $this->environment;
        }

        if ($this->sendOptions->release && $this->release !== null && $this->release !== '') {
            $payload['release'] = $this->release;
        }

        $contexts = $this->buildContexts();
        if ($throwable instanceof Throwable) {
            $db = DatabaseExceptionContext::fromThrowable($throwable);
            if ($db !== null) {
                $contexts['db'] = $db;
            }
        }
        if ($contexts !== []) {
            $payload['contexts'] = $contexts;
        }

        $user = $this->resolveUser();
        if ($user !== null) {
            $payload['user'] = $user;
        }

        if ($this->scope instanceof Scope) {
            $extra += $this->scope->getExtra();
        }
        if ($extra !== []) {
            $payload['extra'] = $extra;
        }

        if ($fingerprint !== null && $fingerprint !== []) {
            $payload['fingerprint'] = $fingerprint;
        }

        $this->seedTraceId();
        $this->attachTags($payload);
        $this->attachTraceId($payload);
        $this->attachRequest($payload);
        $this->attachBreadcrumbs($payload);

        if ($throwable instanceof Throwable) {
            $payload['exception'] = [
                'values' => $this->serializeExceptions($throwable),
            ];
            if ($this->sendOptions->stacktrace) {
                $payload['culprit'] = $this->guessCulprit($throwable);
            }
            if ($message === '') {
                $payload['message'] = SensitiveValueRedactor::redactMessage($throwable->getMessage());
            }
        } elseif ($this->sendOptions->stacktrace) {
            $this->attachCurrentStacktrace($payload);
        }

        return new Envelope($this->header($dsn, $eventId, $sentAt), ['type' => 'event', 'content_type' => 'application/json'], $payload);
    }

    /**
     * Performance transaction envelope (item type `transaction`).
     *
     * @param list<array<string, mixed>> $spans
     * @param array<string, mixed> $extra
     */
    public function transactionEnvelope(
        Dsn $dsn,
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): Envelope {
        $eventId = ($this->eventIdGenerator)();
        $sentAt  = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        $payload = [
            'event_id'        => $eventId,
            'type'            => 'transaction',
            'transaction'     => $transactionName,
            'start_timestamp' => $startTimestamp,
            'timestamp'       => $endTimestamp,
            'platform'        => 'php',
            'spans'           => $spans,
        ];

        if ($this->sendOptions->environment) {
            $payload['environment'] = $this->environment;
        }
        if ($this->sendOptions->release && $this->release !== null && $this->release !== '') {
            $payload['release'] = $this->release;
        }
        if ($extra !== []) {
            $payload['extra'] = $extra;
        }

        $this->seedTraceId();
        $this->attachTags($payload);
        $this->attachTraceId($payload);
        $this->attachRequest($payload);
        $this->attachBreadcrumbs($payload);

        return new Envelope($this->header($dsn, $eventId, $sentAt), ['type' => 'transaction', 'content_type' => 'application/json'], $payload);
    }

    /**
     * `queue_snapshot` envelope: message counts per queue transport (numbers only, no message bodies).
     *
     * @param list<array{name: string, count: int|null, failure: bool}> $transports
     */
    public function queueSnapshotEnvelope(Dsn $dsn, array $transports): Envelope
    {
        $eventId = ($this->eventIdGenerator)();
        $now     = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $payload = [
            'event_id'   => $eventId,
            'type'       => 'queue_snapshot',
            'timestamp'  => (float) $now->format('U.u'),
            'platform'   => 'php',
            'transports' => $transports,
        ];
        if ($this->sendOptions->environment) {
            $payload['environment'] = $this->environment;
        }
        if ($this->sendOptions->serverName) {
            $payload['server_name'] = $this->serverName;
        }

        return new Envelope(
            $this->header($dsn, $eventId, $now->format('Y-m-d\TH:i:s.u\Z')),
            ['type' => 'queue_snapshot', 'content_type' => 'application/json'],
            $payload,
        );
    }

    /**
     * `deploy` envelope (release marker for Beacon analytics / suspect release).
     *
     * @param array{release: string, environment?: string|null, commit?: string|null, url?: string|null, name?: string|null, deployed_at?: float|null} $deploy
     */
    public function deployEnvelope(Dsn $dsn, array $deploy): Envelope
    {
        $eventId = ($this->eventIdGenerator)();
        $now     = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $payload = [
            'event_id'    => $eventId,
            'type'        => 'deploy',
            'release'     => $deploy['release'],
            'environment' => $deploy['environment'] ?? ($this->sendOptions->environment ? $this->environment : null),
            'deployed_at' => $deploy['deployed_at'] ?? (float) $now->format('U.u'),
            'platform'    => 'php',
        ];
        foreach (['commit', 'url', 'name'] as $key) {
            $value = $deploy[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $payload[$key] = $value;
            }
        }
        if ($payload['environment'] === null) {
            unset($payload['environment']);
        }

        return new Envelope(
            $this->header($dsn, $eventId, $now->format('Y-m-d\TH:i:s.u\Z')),
            ['type' => 'deploy', 'content_type' => 'application/json'],
            $payload,
        );
    }

    /**
     * `schedule_catalog` envelope (scheduler check-ins): schedule names, recurring ids, trigger descriptions only.
     *
     * @param list<array{name: string, entries: list<array<string, mixed>>}> $schedules
     */
    public function scheduleCatalogEnvelope(Dsn $dsn, array $schedules): Envelope
    {
        return $this->metadataEnvelope($dsn, 'schedule_catalog', ['schedules' => $schedules]);
    }

    /**
     * `check_in` envelope (one scheduled run status: `in_progress`, `ok` or `error`).
     *
     * @param array<string, mixed> $checkIn
     */
    public function checkInEnvelope(Dsn $dsn, array $checkIn): Envelope
    {
        return $this->metadataEnvelope($dsn, 'check_in', ['check_in' => $checkIn]);
    }

    /**
     * {@see eventEnvelope()} encoded (BeaconBundle `EnvelopeBuilder::buildEventEnvelope()` signature).
     *
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     *
     * @throws EnvelopeEncodingException
     */
    public function buildEventEnvelope(
        Dsn $dsn,
        string $message,
        string $level = 'error',
        ?Throwable $throwable = null,
        array $extra = [],
        ?array $fingerprint = null,
    ): string {
        return $this->eventEnvelope($dsn, $message, $level, $throwable, $extra, $fingerprint)->encode();
    }

    /**
     * {@see transactionEnvelope()} encoded.
     *
     * @param list<array<string, mixed>> $spans
     * @param array<string, mixed> $extra
     *
     * @throws EnvelopeEncodingException
     */
    public function buildTransactionEnvelope(
        Dsn $dsn,
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): string {
        return $this->transactionEnvelope($dsn, $transactionName, $startTimestamp, $endTimestamp, $spans, $extra)->encode();
    }

    /**
     * {@see queueSnapshotEnvelope()} encoded.
     *
     * @param list<array{name: string, count: int|null, failure: bool}> $transports
     *
     * @throws EnvelopeEncodingException
     */
    public function buildQueueSnapshotEnvelope(Dsn $dsn, array $transports): string
    {
        return $this->queueSnapshotEnvelope($dsn, $transports)->encode();
    }

    /**
     * {@see deployEnvelope()} encoded.
     *
     * @param array{release: string, environment?: string|null, commit?: string|null, url?: string|null, name?: string|null, deployed_at?: float|null} $deploy
     *
     * @throws EnvelopeEncodingException
     */
    public function buildDeployEnvelope(Dsn $dsn, array $deploy): string
    {
        return $this->deployEnvelope($dsn, $deploy)->encode();
    }

    /**
     * {@see scheduleCatalogEnvelope()} encoded.
     *
     * @param list<array{name: string, entries: list<array<string, mixed>>}> $schedules
     *
     * @throws EnvelopeEncodingException
     */
    public function buildScheduleCatalogEnvelope(Dsn $dsn, array $schedules): string
    {
        return $this->scheduleCatalogEnvelope($dsn, $schedules)->encode();
    }

    /**
     * {@see checkInEnvelope()} encoded.
     *
     * @param array<string, mixed> $checkIn
     *
     * @throws EnvelopeEncodingException
     */
    public function buildCheckInEnvelope(Dsn $dsn, array $checkIn): string
    {
        return $this->checkInEnvelope($dsn, $checkIn)->encode();
    }

    /**
     * Envelope header line: `event_id`, optional `dsn` (per {@see EnvelopeDsnMode}), `sent_at`.
     *
     * @return array<string, string>
     */
    private function header(Dsn $dsn, string $eventId, string $sentAt): array
    {
        $header = ['event_id' => $eventId];
        if ($this->dsnMode === EnvelopeDsnMode::Full) {
            $header['dsn'] = $dsn->toString();
        } elseif ($this->dsnMode === EnvelopeDsnMode::Public) {
            $header['dsn'] = $dsn->toPublicString();
        }
        $header['sent_at'] = $sentAt;

        return $header;
    }

    /**
     * Shared framing for metadata-only items: `event_id`, `type`, `timestamp`, `platform`, optional `environment` /
     * `server_name`, then `$body`.
     *
     * @param array<string, mixed> $body
     */
    private function metadataEnvelope(Dsn $dsn, string $type, array $body): Envelope
    {
        $eventId = ($this->eventIdGenerator)();
        $now     = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $payload = [
            'event_id'  => $eventId,
            'type'      => $type,
            'timestamp' => (float) $now->format('U.u'),
            'platform'  => 'php',
        ];
        if ($this->sendOptions->environment) {
            $payload['environment'] = $this->environment;
        }
        if ($this->sendOptions->serverName) {
            $payload['server_name'] = $this->serverName;
        }
        $payload += $body;

        return new Envelope(
            $this->header($dsn, $eventId, $now->format('Y-m-d\TH:i:s.u\Z')),
            ['type' => $type, 'content_type' => 'application/json'],
            $payload,
        );
    }

    /**
     * Explicit scope user first, else the provider's user when `send.user` is enabled.
     *
     * @return array<string, mixed>|null
     */
    private function resolveUser(): ?array
    {
        $user = $this->scope?->getUser();
        if ($user !== null) {
            return $user;
        }

        if ($this->sendOptions->user && $this->userContextProvider instanceof UserContextProviderInterface) {
            $provided = $this->userContextProvider->getUserContext();
            if ($provided !== null && $provided !== []) {
                return $provided;
            }
        }

        return null;
    }

    /**
     * Ensure a correlation id exists on the scope before tags are copied.
     */
    private function seedTraceId(): void
    {
        if (!$this->traceIdProvider instanceof TraceIdProvider) {
            return;
        }

        $this->scope?->setTag('trace_id', $this->traceIdProvider->getOrCreate());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function attachTraceId(array &$payload): void
    {
        if (!$this->traceIdProvider instanceof TraceIdProvider) {
            return;
        }

        $traceId = $this->traceIdProvider->getOrCreate();
        if (!isset($payload['extra']) || !is_array($payload['extra'])) {
            $payload['extra'] = [];
        }
        $payload['extra'] += ['trace_id' => $traceId];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function attachRequest(array &$payload): void
    {
        if (!$this->sendOptions->request || !$this->requestProvider instanceof RequestContextProviderInterface) {
            return;
        }

        $request = $this->requestProvider->getRequest();
        if (!$request instanceof RequestSnapshot) {
            return;
        }

        // Secret-like query parameters (token, api_key, …) and URL userinfo are redacted.
        $requestContext = [
            'url'          => SensitiveValueRedactor::redactUrl($request->url),
            'method'       => $request->method,
            'query_string' => SensitiveValueRedactor::redactQueryString($request->queryString ?? ''),
        ];

        $http = $this->httpSnapshot($request);
        foreach (['route', 'controller', 'query_keys', 'client'] as $key) {
            if (isset($http[$key])) {
                $requestContext[$key] = $http[$key];
            }
        }

        $headers = [];
        foreach (self::REQUEST_HEADERS as $headerName) {
            if (isset($request->headers[$headerName])) {
                $value                = $request->headers[$headerName];
                $headers[$headerName] = $headerName === 'referer' ? SensitiveValueRedactor::redactUrl($value) : $value;
            }
        }
        if ($headers !== []) {
            $requestContext['headers'] = $headers;
        }

        $payload['request'] = $requestContext;

        if (!isset($payload['contexts']) || !is_array($payload['contexts'])) {
            $payload['contexts'] = [];
        }
        $payload['contexts']['request'] = $requestContext;

        // Keep listener-style keys when callers did not already set them in extra.
        if (!isset($payload['extra']) || !is_array($payload['extra'])) {
            $payload['extra'] = [];
        }
        $payload['extra'] += [
            'request_uri'    => $requestContext['url'],
            'request_method' => $requestContext['method'],
        ];
        if ($http !== [] && !isset($payload['extra']['http'])) {
            $payload['extra']['http'] = $http;
        }
    }

    /**
     * Same keys and order as BeaconBundle's `HttpRequestSnapshot::fromRequest()`.
     *
     * @return array{route?: string, controller?: string, query_keys?: list<string>, client?: array{ip?: string, user_agent?: string}}
     */
    private function httpSnapshot(RequestSnapshot $request): array
    {
        $http = [];
        if ($request->route !== null && $request->route !== '') {
            $http['route'] = $request->route;
        }
        if ($request->controller !== null && $request->controller !== '') {
            $http['controller'] = $request->controller;
        }
        if ($request->queryKeys !== []) {
            $http['query_keys'] = $request->queryKeys;
        }

        if ($this->sendOptions->client) {
            $client = [];
            if ($request->clientIp !== null && $request->clientIp !== '') {
                $client['ip'] = $request->clientIp;
            }
            if ($request->userAgent !== null && $request->userAgent !== '') {
                $client['user_agent'] = $request->userAgent;
            }
            if ($client !== []) {
                $http['client'] = $client;
            }
        }

        return $http;
    }

    /**
     * Attaches a current PHP stacktrace for message events (no Throwable).
     *
     * @param array<string, mixed> $payload
     */
    private function attachCurrentStacktrace(array &$payload): void
    {
        $traceFlags = $this->sendOptions->stackVars ? 0 : DEBUG_BACKTRACE_IGNORE_ARGS;
        $trace      = array_values(array_filter(
            debug_backtrace($traceFlags),
            fn (array $frame): bool => !$this->isImplementationFrame($frame),
        ));

        if ($trace === []) {
            return;
        }

        $payload['stacktrace'] = [
            'frames' => $this->framesFromPhpTrace($trace),
        ];
        $payload['culprit'] = $this->formatCulprit($trace, (string) ($trace[0]['file'] ?? 'unknown'), (int) ($trace[0]['line'] ?? 0));
    }

    /**
     * True for beacon-php (and configured adapter) implementation frames, never for application code or tests.
     *
     * @param array<string, mixed> $frame
     */
    private function isImplementationFrame(array $frame): bool
    {
        $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
        if ($class !== '') {
            if (str_starts_with($class, 'Nowo\\Beacon\\') && !str_starts_with($class, 'Nowo\\Beacon\\Tests\\')) {
                return true;
            }
            foreach ($this->internalClassPrefixes as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    return true;
                }
            }
        }

        $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
        if ($file === '') {
            return false;
        }

        // This class lives in src/Envelope → package src/ root.
        foreach ([dirname(__DIR__) . DIRECTORY_SEPARATOR, ...$this->internalPaths] as $path) {
            if (str_starts_with($file, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function attachTags(array &$payload): void
    {
        $tags = $this->scope?->getTags() ?? [];
        if ($tags === []) {
            return;
        }

        $payload['tags'] = $tags;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function attachBreadcrumbs(array &$payload): void
    {
        $crumbs = $this->breadcrumbBuffer?->all() ?? [];
        if ($crumbs === []) {
            return;
        }

        $payload['breadcrumbs'] = ['values' => $crumbs];
        $this->breadcrumbBuffer?->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContexts(): array
    {
        $contexts = [];

        if ($this->sendOptions->runtime) {
            $contexts['runtime'] = [
                'name'    => 'php',
                'version' => PHP_VERSION,
            ];
        }

        if ($this->sendOptions->framework && $this->framework !== null) {
            $contexts['framework'] = [
                'name'    => $this->framework['name'],
                'version' => $this->framework['version'],
            ];
        }

        if ($this->sendOptions->os) {
            $contexts['os'] = [
                'name'    => PHP_OS_FAMILY,
                'version' => php_uname('r'),
            ];
        }

        return $contexts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeExceptions(Throwable $throwable): array
    {
        $values  = [];
        $current = $throwable;

        while ($current instanceof Throwable) {
            $entry = [
                'type'  => $current::class,
                'value' => SensitiveValueRedactor::redactMessage($current->getMessage()),
            ];
            if ($this->sendOptions->stacktrace) {
                $entry['stacktrace'] = [
                    'frames' => $this->framesFromTrace($current),
                ];
            }
            $values[] = $entry;
            $current  = $current->getPrevious();
        }

        return array_reverse($values);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function framesFromTrace(Throwable $throwable): array
    {
        $frames = [
            $this->normalizeFrame([
                'file'     => $throwable->getFile(),
                'line'     => $throwable->getLine(),
                'function' => null,
            ]),
        ];

        foreach ($throwable->getTrace() as $frame) {
            $frames[] = $this->normalizeFrame($frame);
        }

        return array_reverse($frames);
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @return list<array<string, mixed>>
     */
    private function framesFromPhpTrace(array $trace): array
    {
        $frames = [];
        foreach ($trace as $frame) {
            $frames[] = $this->normalizeFrame($frame);
        }

        return array_reverse($frames);
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @return array<string, mixed>
     */
    private function normalizeFrame(array $frame): array
    {
        $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null;
        $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;

        $normalized = [
            'filename' => $file ?? '[internal]',
            'lineno'   => $line,
            'function' => $this->frameFunction($frame),
            'in_app'   => $this->isInApp($file),
        ];

        if ($file !== null) {
            $normalized['abs_path'] = $file;
        }

        if ($this->sendOptions->stacktrace && $file !== null && $line > 0) {
            $normalized += $this->readSourceContext($file, $line);
        }

        if ($this->sendOptions->stacktrace && $this->sendOptions->stackVars) {
            $vars = FrameVarsSerializer::fromPhpFrame($frame);
            if ($vars !== []) {
                $normalized['vars'] = $vars;
            }
        }

        return $normalized;
    }

    /**
     * @return array{pre_context?: list<string>, context_line?: string, post_context?: list<string>}
     */
    private function readSourceContext(string $file, int $lineno): array
    {
        $lines = $this->loadSourceLines($file);
        if ($lines === null) {
            return [];
        }

        $index = $lineno - 1;
        if ($index >= count($lines)) {
            return [];
        }

        $context = max(0, $this->stackContextLines);
        $start   = max(0, $index - $context);
        $end     = min(count($lines) - 1, $index + $context);

        $pre = [];
        for ($i = $start; $i < $index; ++$i) {
            $pre[] = $lines[$i];
        }

        $post = [];
        for ($i = $index + 1; $i <= $end; ++$i) {
            $post[] = $lines[$i];
        }

        return [
            'pre_context'  => $pre,
            'context_line' => $lines[$index],
            'post_context' => $post,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function loadSourceLines(string $file): ?array
    {
        if (array_key_exists($file, $this->sourceLineCache)) {
            $lines = $this->sourceLineCache[$file];
            unset($this->sourceLineCache[$file]);

            return $this->sourceLineCache[$file] = $lines;
        }

        while (count($this->sourceLineCache) >= self::SOURCE_LINE_CACHE_MAX_FILES) {
            unset($this->sourceLineCache[array_key_first($this->sourceLineCache)]);
        }

        return $this->sourceLineCache[$file] = $this->readSourceLines($file);
    }

    /**
     * @return list<string>|null
     */
    private function readSourceLines(string $file): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $size = filesize($file);
        if ($size === false || $size > self::MAX_SOURCE_FILE_BYTES) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        return $lines === false ? null : $lines;
    }

    /**
     * Whether the path looks like application code (excludes vendor/cache/node_modules).
     */
    private function isInApp(?string $file): bool
    {
        if ($file === null || $file === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $file);
        foreach (['/vendor/', '/var/cache/', '/node_modules/'] as $exclude) {
            if (str_contains($normalized, $exclude)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Best-effort culprit from the top exception frame (of the throwable chosen by the culprit resolver).
     */
    private function guessCulprit(Throwable $throwable): string
    {
        if ($this->culpritThrowableResolver instanceof Closure) {
            $throwable = ($this->culpritThrowableResolver)($throwable);
        }

        return $this->formatCulprit($throwable->getTrace(), $throwable->getFile(), $throwable->getLine());
    }

    /**
     * Human-readable culprit (class::method or file:line fallback).
     *
     * @param array<int, array<string, mixed>> $trace
     */
    private function formatCulprit(array $trace, string $file, int $line): string
    {
        if ($trace === []) {
            return $file . ':' . $line;
        }

        return $this->frameFunction($trace[0]) ?? $file;
    }

    /**
     * `Class->method` / `Class::method` / `function` of a backtrace frame (null when it has none).
     *
     * @param array<string, mixed> $frame
     */
    private function frameFunction(array $frame): ?string
    {
        $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null;
        if ($function !== null && isset($frame['class'], $frame['type']) && is_string($frame['class']) && is_string($frame['type'])) {
            return $frame['class'] . $frame['type'] . $function;
        }

        return $function;
    }
}
