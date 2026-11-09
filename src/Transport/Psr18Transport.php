<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Nowo\Beacon\Dsn\Dsn;
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
 * - Symfony `Psr18Client` (injected or discovered): wrapped once with `max_redirects: 0` and, when `$timeout` is set,
 *   `timeout` / `max_duration`;
 * - any other PSR-18 client: beacon-php cannot change its redirect policy. A 3xx response is treated as a failed,
 *   non-retryable send (never re-sent), but a client that follows redirects internally has already sent the request
 *   to the target: configure such a client without redirects (and with a timeout) yourself.
 *
 * Sends `Content-Type: application/x-beacon-envelope`, the `User-Agent` and `X-Beacon-Auth`; the URL carries the
 * project id only.
 */
final readonly class Psr18Transport implements TransportInterface
{
    private IngestResponseHandler $responses;

    private string $userAgent;

    private ClientInterface $httpClient;

    public function __construct(
        ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Dsn $dsn,
        ?LoggerInterface $logger = null,
        ?string $userAgent = null,
        private ?float $timeout = null,
    ) {
        $this->responses = new IngestResponseHandler($dsn, $logger);
        $this->userAgent = $userAgent ?? Version::userAgent();

        if ($httpClient instanceof SymfonyPsr18Client) {
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
        try {
            $request = $this->requestFactory->createRequest('POST', $this->dsn->getEnvelopeUrl())
                ->withHeader('Content-Type', 'application/x-beacon-envelope')
                ->withHeader('User-Agent', $this->userAgent)
                ->withHeader('X-Beacon-Auth', $this->dsn->getBeaconAuthHeader())
                ->withBody($this->streamFactory->createStream($envelopeBody));

            $response = $this->dispatch($request);
        } catch (Throwable $exception) {
            return $this->responses->fromFailure($exception);
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

    private static function header(ResponseInterface $response, string $name): ?string
    {
        $values = $response->getHeader($name);

        return $values[0] ?? null;
    }
}
