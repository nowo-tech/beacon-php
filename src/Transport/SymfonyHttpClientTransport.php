<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

use Nowo\Beacon\Dsn\Dsn;
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
 */
final class SymfonyHttpClientTransport implements TransportInterface
{
    public const DEFAULT_MAX_PENDING = 100;

    private readonly IngestResponseHandler $responses;

    private readonly string $userAgent;

    /** @var list<ResponseInterface> */
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
    ) {
        $this->responses = new IngestResponseHandler($dsn, $logger);
        $this->userAgent = $userAgent ?? Version::userAgent();
    }

    public function send(string $envelopeBody): TransportResult
    {
        $response = $this->start($envelopeBody, $this->timeout);
        if ($response instanceof TransportResult) {
            return $response;
        }

        if (!$this->async) {
            return $this->finalize($response);
        }

        while (count($this->pending) >= max(1, $this->maxPending)) {
            $oldest = array_shift($this->pending);
            if ($oldest instanceof ResponseInterface) {
                $this->finalize($oldest);
            }
        }
        $this->pending[] = $response;

        return TransportResult::queued();
    }

    public function flush(float $timeout): bool
    {
        $deadline = microtime(true) + max(0.0, $timeout);
        $complete = true;
        while ($this->pending !== []) {
            $response = array_shift($this->pending);
            if (microtime(true) >= $deadline) {
                $response->cancel();
                $complete = false;

                continue;
            }
            $this->finalize($response);
        }

        return $complete;
    }

    /**
     * Number of started, not yet completed requests (async mode).
     */
    public function countPending(): int
    {
        return count($this->pending);
    }

    private function start(string $envelopeBody, float $timeout): ResponseInterface|TransportResult
    {
        $options = [
            'headers' => [
                'Content-Type'  => 'application/x-beacon-envelope',
                'User-Agent'    => $this->userAgent,
                'X-Beacon-Auth' => $this->dsn->getBeaconAuthHeader(),
            ],
            'body'         => $envelopeBody,
            'timeout'      => $timeout,
            'max_duration' => $timeout,
        ];

        if ($this->maxRedirects !== null) {
            $options['max_redirects'] = $this->maxRedirects;
        }

        if (!$this->verifyPeer) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }

        try {
            return $this->httpClient->request('POST', $this->dsn->getEnvelopeUrl(), $options);
        } catch (Throwable $exception) {
            return $this->responses->fromFailure($exception);
        }
    }

    private function finalize(ResponseInterface $response): TransportResult
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
