<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Ingest\IngestPathResolver;
use Nowo\Beacon\Ingest\IngestPaths;
use Nowo\Beacon\Version;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;
use Throwable;

/**
 * Synchronous transport over any PSR-18 client (Guzzle, Symfony `Psr18Client`, php-http/discovery, …).
 *
 * Redirects are never followed, because a 307/308 would replay `X-Beacon-Auth` (and the body) to the target host:
 *
 * - Guzzle (`GuzzleHttp\ClientInterface`, injected or discovered): every send passes `allow_redirects: false`,
 *   `http_errors: false` and, when `$timeout` is set, `timeout` / `connect_timeout`;
 * - Symfony `Psr18Client` ≥ 6.2 (injected or discovered): wrapped once with `max_redirects: 0` and, when `$timeout` is set,
 *   `timeout` / `max_duration`;
 * - any other PSR-18 client: beacon-php cannot change its redirect policy. A 3xx response is treated as a failed,
 *   non-retryable send (never re-sent), but a client that follows redirects internally has already sent the request
 *   to the target: configure such a client without redirects (and with a timeout) yourself.
 *
 * Sends `Content-Type: application/x-beacon-envelope`, the `User-Agent` and `X-Beacon-Auth`; the URL carries the
 * project id only.
 *
 * Ingest path: the versioned `/api/ingest/v1/{projectId}/envelope/` first; while the target is unknown
 * (`ingest_paths: auto`, see {@see IngestPathResolver}) a 404 / 405 there re-sends the same body once to the legacy
 * `/api/{projectId}/envelope/` and only that answer is logged. No other status is re-sent.
 */
final readonly class Psr18Transport implements TransportInterface
{
    private IngestResponseHandler $responses;

    private string $userAgent;

    private ClientInterface $httpClient;

    private IngestPathResolver $ingestPaths;

    public function __construct(
        ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Dsn $dsn,
        ?LoggerInterface $logger = null,
        ?string $userAgent = null,
        private ?float $timeout = null,
        ?IngestPathResolver $ingestPaths = null,
    ) {
        $this->responses   = new IngestResponseHandler($dsn, $logger);
        $this->userAgent   = $userAgent ?? Version::userAgent();
        $this->ingestPaths = $ingestPaths ?? IngestPathResolver::shared();

        if ($httpClient instanceof SymfonyPsr18Client && self::canWrapWithoutRedirects($httpClient)) {
            $options = ['max_redirects' => 0];
            if ($timeout !== null) {
                $options += ['timeout' => $timeout, 'max_duration' => $timeout];
            }
            $httpClient = $httpClient->withOptions($options);
        }
        $this->httpClient = $httpClient;
    }

    public function send(string $envelopeBody): TransportResult
    {
        $probing   = $this->ingestPaths->isProbing($this->dsn);
        $versioned = $this->ingestPaths->useVersioned($this->dsn);
        $response  = $this->post($envelopeBody, $versioned);
        if ($response instanceof TransportResult) {
            return $response;
        }

        if ($versioned && $probing && $this->ingestPaths->versionedAnswered($this->dsn, $response->getStatusCode())) {
            $response = $this->post($envelopeBody, false);
            if ($response instanceof TransportResult) {
                return $response;
            }
            $this->ingestPaths->legacyAnswered($this->dsn, $response->getStatusCode());
        }

        return $this->responses->fromStatus(
            $response->getStatusCode(),
            static fn (string $name): ?string => self::header($response, $name),
        );
    }

    public function flush(float $timeout): bool
    {
        return true;
    }

    /**
     * Envelope URL the next send starts with (versioned or legacy, see {@see IngestPathResolver}).
     */
    public function getEnvelopeUrl(): string
    {
        return IngestPaths::envelopeUrl($this->dsn, $this->ingestPaths->useVersioned($this->dsn));
    }

    /**
     * One POST to the versioned or legacy envelope URL; a failure is logged and returned.
     */
    private function post(string $envelopeBody, bool $versioned): ResponseInterface|TransportResult
    {
        try {
            $request = $this->requestFactory->createRequest('POST', IngestPaths::envelopeUrl($this->dsn, $versioned))
                ->withHeader('Content-Type', 'application/x-beacon-envelope')
                ->withHeader('User-Agent', $this->userAgent)
                ->withHeader('X-Beacon-Auth', $this->dsn->getBeaconAuthHeader())
                ->withBody($this->streamFactory->createStream($envelopeBody));

            return $this->dispatch($request);
        } catch (Throwable $exception) {
            return $this->responses->fromFailure($exception);
        }
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        if ($this->httpClient instanceof GuzzleClientInterface) {
            $options = ['allow_redirects' => false, 'http_errors' => false];
            if ($this->timeout !== null) {
                $options += ['timeout' => $this->timeout, 'connect_timeout' => $this->timeout];
            }

            return $this->httpClient->send($request, $options);
        }

        return $this->httpClient->sendRequest($request);
    }

    /**
     * Symfony `Psr18Client` with `withOptions()` (symfony/http-client ≥ 6.2, also enforced by a composer conflict);
     * an older one is handled like any other PSR-18 client (a 3xx is a failed send).
     *
     * @internal
     */
    public static function canWrapWithoutRedirects(ClientInterface $client): bool
    {
        // Always true for the installed symfony/http-client; false for < 6.2 (no withOptions()).
        return $client instanceof SymfonyPsr18Client && method_exists($client, 'withOptions'); // @phpstan-ignore function.alreadyNarrowedType
    }

    private static function header(ResponseInterface $response, string $name): ?string
    {
        $values = $response->getHeader($name);

        return $values[0] ?? null;
    }
}
