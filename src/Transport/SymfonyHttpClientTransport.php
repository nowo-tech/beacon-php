<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Ingest\IngestPathResolver;
use Nowo\Beacon\Ingest\IngestPaths;
use Nowo\Beacon\Version;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function array_shift;
use function count;
use function max;
use function microtime;

/**
 * Optional transport over `symfony/http-client-contracts` (requires an implementation such as symfony/http-client).
 *
 * Same request as BeaconBundle's `EnvelopeTransport`: `timeout` and `max_duration` per request, `verify_peer` /
 * `verify_host` switchable for local self-signed Beacon (dev only). Redirects are not followed by default
 * (`$maxRedirects = 0`): a 307/308 to another host would otherwise carry `X-Beacon-Auth` there. `null` keeps the
 * client's own redirect policy (BeaconBundle ≤ 1.19 behaviour).
 *
 * With `$async`, {@see send()} only starts the request (non-blocking, like the bundle's `AsyncEnvelopeTransport`) and
 * {@see flush()} reads the statuses. At most `$maxPending` responses are kept: starting one more completes the
 * oldest first. {@see flush()} stops at its deadline and cancels what is still running (one response that is
 * being read can still take up to `$timeout`, the per-request limit).
 *
 * Ingest path: the versioned `/api/ingest/v1/{projectId}/envelope/` first; while the target is unknown
 * (`ingest_paths: auto`, see {@see IngestPathResolver}) a 404 / 405 there re-sends the same body once to the legacy
 * `/api/{projectId}/envelope/` and only that answer is logged. In async mode a pending request keeps its body only
 * while the target is unknown; the legacy retry then happens synchronously when its status is read. No other status
 * is re-sent.
 */
final class SymfonyHttpClientTransport implements TransportInterface
{
    public const DEFAULT_MAX_PENDING = 100;

    private readonly IngestResponseHandler $responses;

    private readonly string $userAgent;

    private readonly IngestPathResolver $ingestPaths;

    /** @var list<array{0: ResponseInterface, 1: bool, 2: string|null}> response, versioned path, body kept for a legacy retry */
    private array $pending = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Dsn $dsn,
        private readonly bool $verifyPeer = true,
        private readonly float $timeout = 5.0,
        ?LoggerInterface $logger = null,
        ?string $userAgent = null,
        private readonly bool $async = false,
        private readonly int $maxPending = self::DEFAULT_MAX_PENDING,
        private readonly ?int $maxRedirects = 0,
        ?IngestPathResolver $ingestPaths = null,
    ) {
        $this->responses   = new IngestResponseHandler($dsn, $logger);
        $this->userAgent   = $userAgent ?? Version::userAgent();
        $this->ingestPaths = $ingestPaths ?? IngestPathResolver::shared();
    }

    public function send(string $envelopeBody): TransportResult
    {
        $probing   = $this->ingestPaths->isProbing($this->dsn);
        $versioned = $this->ingestPaths->useVersioned($this->dsn);
        $response  = $this->start($envelopeBody, $versioned);
        if ($response instanceof TransportResult) {
            return $response;
        }

        $started = [$response, $versioned, $probing ? $envelopeBody : null];
        if (!$this->async) {
            return $this->finalize($started);
        }

        while (count($this->pending) >= max(1, $this->maxPending)) {
            $oldest = array_shift($this->pending);
            if ($oldest !== null) {
                $this->finalize($oldest);
            }
        }
        $this->pending[] = $started;

        return TransportResult::queued();
    }

    public function flush(float $timeout): bool
    {
        $deadline = microtime(true) + max(0.0, $timeout);
        $complete = true;
        while ($this->pending !== []) {
            $started = array_shift($this->pending);
            if (microtime(true) >= $deadline) {
                $started[0]->cancel();
                $complete = false;

                continue;
            }
            $this->finalize($started);
        }

        return $complete;
    }

    /**
     * Responses that were never flushed are completed here (each bounded by `$timeout`), so Symfony's response
     * destructor never throws a 3xx / 4xx / 5xx at script end. Prefer calling {@see flush()} with a deadline.
     */
    public function __destruct()
    {
        $pending       = $this->pending;
        $this->pending = [];
        foreach ($pending as $started) {
            $this->finalize($started); // never throws: failures become a TransportResult
        }
    }

    /**
     * Number of started, not yet completed requests (async mode).
     */
    public function countPending(): int
    {
        return count($this->pending);
    }

    /**
     * Envelope URL the next send starts with (versioned or legacy, see {@see IngestPathResolver}).
     */
    public function getEnvelopeUrl(): string
    {
        return IngestPaths::envelopeUrl($this->dsn, $this->ingestPaths->useVersioned($this->dsn));
    }

    private function start(string $envelopeBody, bool $versioned): ResponseInterface|TransportResult
    {
        $options = [
            'headers' => [
                'Content-Type'  => 'application/x-beacon-envelope',
                'User-Agent'    => $this->userAgent,
                'X-Beacon-Auth' => $this->dsn->getBeaconAuthHeader(),
            ],
            'body'         => $envelopeBody,
            'timeout'      => $this->timeout,
            'max_duration' => $this->timeout,
        ];

        if ($this->maxRedirects !== null) {
            $options['max_redirects'] = $this->maxRedirects;
        }

        if (!$this->verifyPeer) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }

        try {
            return $this->httpClient->request('POST', IngestPaths::envelopeUrl($this->dsn, $versioned), $options);
        } catch (Throwable $exception) {
            return $this->responses->fromFailure($exception);
        }
    }

    /**
     * Read the status of a started request; when it went to the versioned path while the target was unknown and the
     * route is missing (404 / 405), re-send the kept body once to the legacy path. Logs exactly one outcome.
     *
     * @param array{0: ResponseInterface, 1: bool, 2: string|null} $started
     */
    private function finalize(array $started): TransportResult
    {
        [$response, $versioned, $bodyForFallback] = $started;
        if ($versioned && $bodyForFallback !== null) {
            $status = $this->statusOf($response);
            if ($status !== null && $this->ingestPaths->versionedAnswered($this->dsn, $status)) {
                $response->cancel();
                $legacy = $this->start($bodyForFallback, false);
                if ($legacy instanceof TransportResult) {
                    return $legacy;
                }

                $legacyStatus = $this->statusOf($legacy);
                if ($legacyStatus !== null) {
                    $this->ingestPaths->legacyAnswered($this->dsn, $legacyStatus);
                }

                return $this->complete($legacy);
            }
        }

        return $this->complete($response);
    }

    /**
     * HTTP status without logging, or null on a transport failure (no HTTP answer).
     */
    private function statusOf(ResponseInterface $response): ?int
    {
        try {
            return $response->getStatusCode();
        } catch (Throwable) {
            return null;
        }
    }

    private function complete(ResponseInterface $response): TransportResult
    {
        try {
            $status = $response->getStatusCode();
        } catch (Throwable $exception) {
            return $this->responses->fromFailure($exception);
        }

        return $this->responses->fromStatus(
            $status,
            static function (string $name) use ($response): ?string {
                try {
                    return $response->getHeaders(false)[$name][0] ?? null;
                } catch (Throwable) {
                    return null;
                }
            },
        );
    }
}
