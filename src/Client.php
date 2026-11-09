<?php

declare(strict_types=1);

namespace Nowo\Beacon;

use Closure;
use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Envelope\Envelope;
use Nowo\Beacon\Envelope\EnvelopeBuilder;
use Nowo\Beacon\Request\GlobalsRequestContextProvider;
use Nowo\Beacon\Scope\Scope;
use Nowo\Beacon\Scrubber\IpStripper;
use Nowo\Beacon\Scrubber\PayloadScrubber;
use Nowo\Beacon\Span\SpanBuffer;
use Nowo\Beacon\Trace\TraceIdProvider;
use Nowo\Beacon\Transport\IngestResponseHandler;
use Nowo\Beacon\Transport\TransportFactory;
use Nowo\Beacon\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Throwable;
use WeakMap;

use function is_array;
use function json_decode;

/**
 * Default Beacon client: builds Envelopes and hands them to a {@see TransportInterface}.
 *
 * Every instance owns its scope, breadcrumbs, spans, trace id and transport: two clients never share state, and
 * constructing one installs no global handler (see {@see Beacon::init()} / {@see ErrorHandler\ErrorHandler}).
 *
 * Pipeline for events and transactions: EnvelopeBuilder (message / exception / URL redaction) → {@see PayloadScrubber}
 * (option `scrub`) → `before_send` → {@see IpStripper} (unless `send.client`) → encode → transport. Deploys, check-ins,
 * schedule catalogs and queue snapshots carry metadata only and skip the hooks, as in BeaconBundle.
 *
 * A capture never throws: a failing builder, hook or transport is logged (PSR-3, flagged with
 * {@see IngestResponseHandler::LOG_CONTEXT_FLAG}) and the capture returns null. Re-entrant captures (an error raised
 * while capturing) are ignored.
 */
final class Client implements ClientInterface, CheckInCapableInterface, DeployCapableInterface, QueueSnapshotCapableInterface, LifecycleInterface
{
    private readonly TransportInterface $transport;

    private readonly EnvelopeBuilder $envelopeBuilder;

    private readonly Scope $scope;

    private readonly BreadcrumbBuffer $breadcrumbs;

    private readonly TraceIdProvider $traceIdProvider;

    private readonly SpanBuffer $spanBuffer;

    private readonly LoggerInterface $logger;

    private readonly PayloadScrubber $scrubber;

    private readonly IpStripper $ipStripper;

    private bool $capturing = false;

    private bool $closed = false;

    private ?string $lastEventId = null;

    /** @var WeakMap<Throwable, string> throwables already sent (option `dedupe`) → their event id */
    private WeakMap $captured;

    /**
     * Inject `$transport` / `$envelopeBuilder` / the state objects only when adapting another integration (the
     * builder must then read the same scope, breadcrumbs and trace provider).
     */
    public function __construct(
        private readonly Options $options,
        ?TransportInterface $transport = null,
        ?EnvelopeBuilder $envelopeBuilder = null,
        ?Scope $scope = null,
        ?BreadcrumbBuffer $breadcrumbs = null,
        ?TraceIdProvider $traceIdProvider = null,
        ?SpanBuffer $spanBuffer = null,
    ) {
        $this->captured        = new WeakMap();
        $this->scope           = $scope ?? new Scope();
        $this->breadcrumbs     = $breadcrumbs ?? new BreadcrumbBuffer();
        $this->traceIdProvider = $traceIdProvider ?? new TraceIdProvider();
        $this->spanBuffer      = $spanBuffer ?? new SpanBuffer();
        $this->logger          = $options->logger ?? new NullLogger();
        $this->scrubber        = new PayloadScrubber();
        $this->ipStripper      = new IpStripper();
        $this->transport       = $transport ?? (new TransportFactory())->create($options);
        $this->envelopeBuilder = $envelopeBuilder ?? new EnvelopeBuilder(
            environment: $options->environment,
            release: $options->release,
            serverName: $options->serverName,
            sendOptions: $options->send,
            userContextProvider: $options->userProvider,
            breadcrumbBuffer: $this->breadcrumbs,
            requestProvider: $options->requestProvider ?? new GlobalsRequestContextProvider(),
            stackContextLines: $options->stackContextLines,
            scope: $this->scope,
            traceIdProvider: $this->traceIdProvider,
            clock: $options->clock,
            dsnMode: $options->envelopeDsn,
            framework: $options->framework,
            culpritThrowableResolver: $options->culpritResolver,
            internalClassPrefixes: $options->internalClassPrefixes,
            internalPaths: $options->internalPaths,
        );
    }

    /**
     * Shortcut for `new Client(Options::fromDsn($dsn))`.
     */
    public static function fromDsn(#[SensitiveParameter] string $dsn): self
    {
        return new self(Options::fromDsn($dsn));
    }

    public function isEnabled(): bool
    {
        return !$this->closed && $this->options->isActive();
    }

    /**
     * With option `dedupe` (default), the same Throwable instance is sent once: a second capture (framework report
     * hook + Monolog channel + middleware, …) returns the first event id without sending.
     */
    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string
    {
        if ($this->options->dedupe && isset($this->captured[$throwable])) {
            return $this->captured[$throwable];
        }

        $eventId = $this->capture(
            fn (Dsn $dsn): Envelope => $this->envelopeBuilder->eventEnvelope($dsn, $throwable->getMessage(), 'error', $throwable, $extra, $fingerprint),
            true,
        );
        if ($eventId !== null && $this->options->dedupe) {
            $this->captured[$throwable] = $eventId;
        }

        return $eventId;
    }

    public function captureMessage(
        string $message,
        string $level = 'error',
        array $extra = [],
        ?array $fingerprint = null,
    ): ?string {
        return $this->capture(
            fn (Dsn $dsn): Envelope => $this->envelopeBuilder->eventEnvelope($dsn, $message, $level, null, $extra, $fingerprint),
            true,
        );
    }

    public function addBreadcrumb(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = [],
    ): void {
        $this->breadcrumbs->add($message, $category, $level, $data);
    }

    public function captureTransaction(
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): ?string {
        return $this->capture(
            function (Dsn $dsn) use ($transactionName, $startTimestamp, $endTimestamp, $spans, $extra): Envelope {
                $all = [...$this->spanBuffer->drain(), ...$spans];

                return $this->envelopeBuilder->transactionEnvelope($dsn, $transactionName, $startTimestamp, $endTimestamp, $all, $extra);
            },
            true,
        );
    }

    /**
     * Deploy markers carry only release metadata, so `before_send` is not applied.
     */
    public function captureDeploy(array $deploy): ?string
    {
        return $this->capture(fn (Dsn $dsn): Envelope => $this->envelopeBuilder->deployEnvelope($dsn, $deploy), false);
    }

    /**
     * Snapshots carry only transport names and counts, so `before_send` is not applied.
     */
    public function captureQueueSnapshot(array $transports): ?string
    {
        return $this->capture(fn (Dsn $dsn): Envelope => $this->envelopeBuilder->queueSnapshotEnvelope($dsn, $transports), false);
    }

    /**
     * Catalogs carry only schedule metadata, so `before_send` is not applied.
     */
    public function captureScheduleCatalog(array $schedules): ?string
    {
        return $this->capture(fn (Dsn $dsn): Envelope => $this->envelopeBuilder->scheduleCatalogEnvelope($dsn, $schedules), false);
    }

    /**
     * Check-ins carry only run metadata, so `before_send` is not applied.
     */
    public function captureCheckIn(array $checkIn): ?string
    {
        return $this->capture(fn (Dsn $dsn): Envelope => $this->envelopeBuilder->checkInEnvelope($dsn, $checkIn), false);
    }

    public function setTag(string $key, mixed $value): void
    {
        $this->scope->setTag($key, $value);
    }

    public function setTags(array $tags): void
    {
        $this->scope->setTags($tags);
    }

    public function getTags(): array
    {
        return $this->scope->getTags();
    }

    public function clearTags(): void
    {
        $this->scope->clearTags();
    }

    /**
     * Identify the user for the next events (`id`, `username`, `email`, …); null clears it.
     *
     * @param array<string, mixed>|null $user
     */
    public function setUser(?array $user): void
    {
        $this->scope->setUser($user);
    }

    /**
     * Extra value merged into every event of the current scope.
     */
    public function setExtra(string $key, mixed $value): void
    {
        $this->scope->setExtra($key, $value);
    }

    /**
     * Use an inbound correlation id (e.g. the `X-Beacon-Trace-Id` request header). Invalid ids are ignored.
     */
    public function setTraceId(string $traceId): void
    {
        $this->traceIdProvider->set($traceId);
    }

    public function flush(?float $timeout = null): bool
    {
        try {
            return $this->transport->flush($timeout ?? $this->options->shutdownTimeout);
        } catch (Throwable $exception) {
            $this->logFailure('Beacon flush failed.', $exception);

            return false;
        }
    }

    public function reset(): void
    {
        $this->scope->reset();
        $this->breadcrumbs->reset();
        $this->spanBuffer->reset();
        $this->traceIdProvider->reset();
        $this->captured = new WeakMap();
    }

    /**
     * Flush, then stop sending (later captures return null).
     */
    public function close(?float $timeout = null): bool
    {
        $flushed      = $this->flush($timeout);
        $this->closed = true;

        return $flushed;
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function getDsn(): ?Dsn
    {
        return $this->options->dsn;
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function getBreadcrumbs(): BreadcrumbBuffer
    {
        return $this->breadcrumbs;
    }

    public function getSpanBuffer(): SpanBuffer
    {
        return $this->spanBuffer;
    }

    public function getTraceIdProvider(): TraceIdProvider
    {
        return $this->traceIdProvider;
    }

    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    public function getEnvelopeBuilder(): EnvelopeBuilder
    {
        return $this->envelopeBuilder;
    }

    /**
     * Id of the last envelope handed to the transport.
     */
    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * @param Closure(Dsn): Envelope $build
     */
    private function capture(Closure $build, bool $applyHooks): ?string
    {
        $dsn = $this->options->dsn;
        if (!$this->isEnabled() || !$dsn instanceof Dsn || $this->capturing) {
            return null;
        }

        $this->capturing = true;
        try {
            $envelope = $build($dsn);
            if ($applyHooks) {
                $envelope = $this->applyHooks($envelope);
                if (!$envelope instanceof Envelope) {
                    return null;
                }
            }

            $this->transport->send($envelope->encode());

            return $this->lastEventId = $envelope->getEventId();
        } catch (Throwable $exception) {
            $this->logFailure('Beacon capture failed.', $exception);

            return null;
        } finally {
            $this->capturing = false;
        }
    }

    /**
     * Scrubber → before_send → IP stripping. Null drops the event.
     */
    private function applyHooks(Envelope $envelope): ?Envelope
    {
        $payload = $envelope->payload;
        if ($this->options->scrub) {
            $payload = $this->scrubber->scrub($payload);
        }

        $beforeSend = $this->options->beforeSend;
        if ($beforeSend instanceof Closure) {
            // Same input as BeaconBundle: the payload as decoded from its JSON line.
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(Envelope::encodeLine($payload), true);
            try {
                $result = $beforeSend($decoded);
            } catch (Throwable) {
                // Fail soft: drop the event so a buggy hook cannot break the host app.
                return null;
            }
            if (!is_array($result)) {
                return null;
            }
            /** @var array<string, mixed> $result */
            $payload = $result;
        }

        if (!$this->options->send->client) {
            $payload = $this->ipStripper->strip($payload);
        }

        return $envelope->withPayload($payload);
    }

    private function logFailure(string $message, Throwable $exception): void
    {
        $handler = $this->options->dsn instanceof Dsn ? new IngestResponseHandler($this->options->dsn) : null;
        $this->logger->error($message, [
            'exception'                             => $handler?->sanitize($exception->getMessage()) ?? $exception->getMessage(),
            'exception_class'                       => $exception::class,
            IngestResponseHandler::LOG_CONTEXT_FLAG => true,
        ]);
    }
}
