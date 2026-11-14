<?php

declare(strict_types=1);

namespace Nowo\Beacon;

use Closure;
use Nowo\Beacon\Context\UserContextProviderInterface;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Dsn\InvalidDsnException;
use Nowo\Beacon\Envelope\EnvelopeDsnMode;
use Nowo\Beacon\Envelope\SendOptions;
use Nowo\Beacon\ErrorHandler\ErrorHandler;
use Nowo\Beacon\Exception\ConfigurationException;
use Nowo\Beacon\Ingest\IngestPathMode;
use Nowo\Beacon\Ingest\IngestPathResolver;
use Nowo\Beacon\Request\RequestContextProviderInterface;
use Nowo\Beacon\Transport\QueuedTransport;
use Nowo\Beacon\Transport\TransportInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClientInterface;
use Throwable;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function getenv;
use function gethostname;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Immutable client configuration.
 *
 * Build it with {@see fromDsn()} or {@see fromArray()} (snake_case keys, the same names as BeaconBundle's
 * `nowo_beacon` configuration where both exist). Unknown keys and wrong types throw {@see ConfigurationException};
 * an invalid DSN throws {@see InvalidDsnException}. Neither message contains the DSN or the secret.
 *
 * An empty DSN (or `enabled: false`) gives a disabled client: every capture is a no-op returning null.
 */
final readonly class Options
{
    public const KEYS = [
        'dsn',
        'enabled',
        'environment',
        'release',
        'server_name',
        'send',
        'stack_context_lines',
        'timeout',
        'verify_peer',
        'shutdown_timeout',
        'before_send',
        'transport',
        'http_client',
        'request_factory',
        'stream_factory',
        'async',
        'queue',
        'logger',
        'clock',
        'envelope_dsn',
        'scrub',
        'dedupe',
        'error_handler',
        'framework',
        'request_provider',
        'user_provider',
        'culprit_resolver',
        'internal_class_prefixes',
        'internal_paths',
        'ingest_paths',
        'ingest_path_resolver',
    ];

    public const DEFAULT_ENVIRONMENT = 'production';

    public const DEFAULT_TIMEOUT = 5.0;

    public const DEFAULT_SHUTDOWN_TIMEOUT = 2.0;

    public const MAX_TIMEOUT = 60.0;

    public const MAX_SHUTDOWN_TIMEOUT = 300.0;

    /**
     * @param (Closure(array<string, mixed>): (array<string, mixed>|null))|null $beforeSend
     * @param array{max_envelopes: int, max_bytes: int}|null $queue
     * @param array{errors: int, capture_silenced: bool, exceptions: bool, fatal: bool, swallow: bool}|null $errorHandler
     * @param array{name: string, version: string}|null $framework
     * @param (Closure(Throwable): Throwable)|null $culpritResolver
     * @param list<string> $internalClassPrefixes
     * @param list<string> $internalPaths
     */
    private function __construct(
        public ?Dsn $dsn,
        public bool $enabled,
        public string $environment,
        public ?string $release,
        public string $serverName,
        public SendOptions $send,
        public int $stackContextLines,
        public float $timeout,
        public bool $verifyPeer,
        public float $shutdownTimeout,
        public ?Closure $beforeSend,
        public ?TransportInterface $transport,
        public Psr18ClientInterface|SymfonyHttpClientInterface|null $httpClient,
        public ?RequestFactoryInterface $requestFactory,
        public ?StreamFactoryInterface $streamFactory,
        public bool $async,
        public ?array $queue,
        public ?LoggerInterface $logger,
        public ?ClockInterface $clock,
        public EnvelopeDsnMode $envelopeDsn,
        public bool $scrub,
        public bool $dedupe,
        public ?array $errorHandler,
        public ?array $framework,
        public ?RequestContextProviderInterface $requestProvider,
        public ?UserContextProviderInterface $userProvider,
        public ?Closure $culpritResolver,
        public array $internalClassPrefixes,
        public array $internalPaths,
        public IngestPathMode $ingestPaths,
        public IngestPathResolver $ingestPathResolver,
    ) {
    }

    /**
     * Options with every default and the given DSN (an empty DSN disables the client).
     *
     * @throws InvalidDsnException
     */
    public static function fromDsn(#[SensitiveParameter] string $dsn): self
    {
        return self::fromArray(['dsn' => $dsn]);
    }

    /**
     * Options from an array. Missing `dsn` / `environment` / `release` fall back to the `BEACON_DSN`,
     * `BEACON_ENVIRONMENT` and `BEACON_RELEASE` environment variables.
     *
     * @param array<array-key, mixed> $options
     *
     * @throws ConfigurationException
     * @throws InvalidDsnException
     */
    public static function fromArray(#[SensitiveParameter] array $options): self
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ConfigurationException(sprintf('Unknown Beacon option "%s". Known options: %s.', $key, implode(', ', self::KEYS)));
            }
        }

        $dsnString = array_key_exists('dsn', $options) ? $options['dsn'] : self::env('BEACON_DSN');
        if ($dsnString !== null && !is_string($dsnString)) {
            throw new ConfigurationException('The "dsn" option must be a string or null.');
        }
        $dsn = $dsnString === null || trim($dsnString) === '' ? null : (new DsnParser())->parse($dsnString);

        $environment = array_key_exists('environment', $options) ? $options['environment'] : (self::env('BEACON_ENVIRONMENT') ?? self::DEFAULT_ENVIRONMENT);
        $release     = array_key_exists('release', $options) ? $options['release'] : self::env('BEACON_RELEASE');
        $serverName  = $options['server_name'] ?? null;

        $send = $options['send'] ?? [];
        if (!is_array($send)) {
            throw new ConfigurationException('The "send" option must be an array of booleans.');
        }

        $envelopeDsn = $options['envelope_dsn'] ?? EnvelopeDsnMode::None;
        if (is_string($envelopeDsn)) {
            $envelopeDsn = EnvelopeDsnMode::tryFrom($envelopeDsn)
                ?? throw new ConfigurationException('The "envelope_dsn" option must be "none", "public" or "full".');
        }
        if (!$envelopeDsn instanceof EnvelopeDsnMode) {
            throw new ConfigurationException('The "envelope_dsn" option must be "none", "public" or "full".');
        }

        [$ingestPaths, $ingestPathResolver] = self::ingestPaths($options);

        return new self(
            dsn: $dsn,
            enabled: self::bool($options, 'enabled', true),
            environment: self::requireString($environment, 'environment'),
            release: self::nullableString($release, 'release'),
            serverName: $serverName === null ? (gethostname() ?: 'unknown') : self::requireString($serverName, 'server_name'),
            send: SendOptions::fromArray($send),
            stackContextLines: self::int($options, 'stack_context_lines', 5, 0),
            timeout: self::float($options, 'timeout', self::DEFAULT_TIMEOUT, 0.1, self::MAX_TIMEOUT),
            verifyPeer: self::bool($options, 'verify_peer', true),
            shutdownTimeout: self::float($options, 'shutdown_timeout', self::DEFAULT_SHUTDOWN_TIMEOUT, 0.0, self::MAX_SHUTDOWN_TIMEOUT),
            beforeSend: self::closure($options, 'before_send'),
            transport: self::instance($options, 'transport', TransportInterface::class),
            httpClient: self::httpClient($options),
            requestFactory: self::instance($options, 'request_factory', RequestFactoryInterface::class),
            streamFactory: self::instance($options, 'stream_factory', StreamFactoryInterface::class),
            async: self::bool($options, 'async', false),
            queue: self::queue($options['queue'] ?? false),
            logger: self::instance($options, 'logger', LoggerInterface::class),
            clock: self::instance($options, 'clock', ClockInterface::class),
            envelopeDsn: $envelopeDsn,
            scrub: self::bool($options, 'scrub', true),
            dedupe: self::bool($options, 'dedupe', true),
            errorHandler: self::errorHandler($options['error_handler'] ?? true),
            framework: self::framework($options['framework'] ?? null),
            requestProvider: self::instance($options, 'request_provider', RequestContextProviderInterface::class),
            userProvider: self::instance($options, 'user_provider', UserContextProviderInterface::class),
            culpritResolver: self::closure($options, 'culprit_resolver'),
            internalClassPrefixes: self::stringList($options, 'internal_class_prefixes'),
            internalPaths: self::stringList($options, 'internal_paths'),
            ingestPaths: $ingestPaths,
            ingestPathResolver: $ingestPathResolver,
        );
    }

    /**
     * Whether events will be sent (enabled and a DSN is set).
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->dsn instanceof Dsn;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function bool(array $options, string $key, bool $default): bool
    {
        $value = $options[$key] ?? $default;
        if (!is_bool($value)) {
            throw new ConfigurationException(sprintf('The "%s" option must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function int(array $options, string $key, int $default, int $min): int
    {
        $value = $options[$key] ?? $default;
        if (!is_int($value) || $value < $min) {
            throw new ConfigurationException(sprintf('The "%s" option must be an integer >= %d.', $key, $min));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function float(array $options, string $key, float $default, float $min, float $max): float
    {
        $value = $options[$key] ?? $default;
        if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
            throw new ConfigurationException(sprintf('The "%s" option must be a finite number between %s and %s.', $key, (string) $min, (string) $max));
        }

        return (float) $value;
    }

    private static function requireString(mixed $value, string $key): string
    {
        if (!is_string($value) || $value === '') {
            throw new ConfigurationException(sprintf('The "%s" option must be a non-empty string.', $key));
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requireString($value, $key);
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function closure(array $options, string $key): ?Closure
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_callable($value)) {
            throw new ConfigurationException(sprintf('The "%s" option must be callable.', $key));
        }

        return Closure::fromCallable($value);
    }

    /**
     * @template T of object
     *
     * @param array<array-key, mixed> $options
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private static function instance(array $options, string $key, string $class): ?object
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!$value instanceof $class) {
            throw new ConfigurationException(sprintf('The "%s" option must be an instance of %s.', $key, $class));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function httpClient(array $options): Psr18ClientInterface|SymfonyHttpClientInterface|null
    {
        $value = $options['http_client'] ?? null;
        if ($value === null || $value instanceof Psr18ClientInterface || $value instanceof SymfonyHttpClientInterface) {
            return $value;
        }

        throw new ConfigurationException('The "http_client" option must be a PSR-18 client or a Symfony HttpClientInterface.');
    }

    /**
     * `ingest_paths` mode and the resolver the bundled transports use: the given `ingest_path_resolver`, the
     * process-wide shared resolver for `auto`, or a fresh one for a forced mode (nothing to remember).
     *
     * @param array<array-key, mixed> $options
     *
     * @return array{0: IngestPathMode, 1: IngestPathResolver}
     */
    private static function ingestPaths(array $options): array
    {
        $resolver = self::instance($options, 'ingest_path_resolver', IngestPathResolver::class);
        $value    = $options['ingest_paths'] ?? null;
        if ($value === null) {
            $mode = $resolver?->mode() ?? IngestPathMode::Auto;
        } else {
            $mode = $value instanceof IngestPathMode ? $value : (is_string($value) ? IngestPathMode::tryFrom($value) : null);
            if ($mode === null) {
                throw new ConfigurationException('The "ingest_paths" option must be "auto", "versioned" or "legacy".');
            }
            if ($resolver !== null && $resolver->mode() !== $mode) {
                throw new ConfigurationException('The "ingest_paths" option and the mode of "ingest_path_resolver" differ.');
            }
        }

        $resolver ??= $mode === IngestPathMode::Auto ? IngestPathResolver::shared() : new IngestPathResolver($mode);

        return [$mode, $resolver];
    }

    /**
     * @return array{max_envelopes: int, max_bytes: int}|null
     */
    private static function queue(mixed $value): ?array
    {
        if ($value === false) {
            return null;
        }
        if ($value === true) {
            $value = [];
        }
        if (!is_array($value)) {
            throw new ConfigurationException('The "queue" option must be a boolean or an array with max_envelopes / max_bytes.');
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, ['max_envelopes', 'max_bytes'], true)) {
                throw new ConfigurationException(sprintf('Unknown "queue" option "%s".', $key));
            }
        }

        return [
            'max_envelopes' => self::int($value, 'max_envelopes', QueuedTransport::DEFAULT_MAX_ENVELOPES, 1),
            'max_bytes'     => self::int($value, 'max_bytes', QueuedTransport::DEFAULT_MAX_BYTES, 1),
        ];
    }

    /**
     * @return array{errors: int, capture_silenced: bool, exceptions: bool, fatal: bool, swallow: bool}|null
     */
    private static function errorHandler(mixed $value): ?array
    {
        if ($value === false) {
            return null;
        }
        if ($value === true) {
            $value = [];
        }
        if (!is_array($value)) {
            throw new ConfigurationException('The "error_handler" option must be a boolean or an array.');
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, ['errors', 'capture_silenced', 'exceptions', 'fatal', 'swallow'], true)) {
                throw new ConfigurationException(sprintf('Unknown "error_handler" option "%s".', $key));
            }
        }

        return [
            'errors'           => self::int($value, 'errors', ErrorHandler::DEFAULT_LEVELS, 0),
            'capture_silenced' => self::bool($value, 'capture_silenced', false),
            'exceptions'       => self::bool($value, 'exceptions', true),
            'fatal'            => self::bool($value, 'fatal', true),
            'swallow'          => self::bool($value, 'swallow', false),
        ];
    }

    /**
     * @return array{name: string, version: string}|null
     */
    private static function framework(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !isset($value['name'], $value['version']) || !is_string($value['name']) || !is_string($value['version'])) {
            throw new ConfigurationException('The "framework" option must be null or ["name" => string, "version" => string].');
        }

        return ['name' => $value['name'], 'version' => $value['version']];
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return list<string>
     */
    private static function stringList(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConfigurationException(sprintf('The "%s" option must be a list of strings.', $key));
        }

        return array_map(static function (mixed $item) use ($key): string {
            if (!is_string($item) || $item === '') {
                throw new ConfigurationException(sprintf('The "%s" option must be a list of non-empty strings.', $key));
            }

            return $item;
        }, $value);
    }
}
