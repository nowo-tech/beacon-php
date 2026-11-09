<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Version;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Synchronous transport over any PSR-18 client (Guzzle, Symfony `Psr18Client`, php-http/discovery, …).
 *
 * PSR-18 has no per-request timeout: configure it on the client you inject (the auto-created Guzzle client gets the
 * `timeout` option, see {@see TransportFactory}). Sends `Content-Type: application/x-beacon-envelope`, the
 * `User-Agent` and `X-Beacon-Auth`; the URL carries the project id only.
 */
final readonly class Psr18Transport implements TransportInterface
{
    private IngestResponseHandler $responses;

    private string $userAgent;

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Dsn $dsn,
        ?LoggerInterface $logger = null,
        ?string $userAgent = null,
    ) {
        $this->responses = new IngestResponseHandler($dsn, $logger);
        $this->userAgent = $userAgent ?? Version::userAgent();
    }

    public function send(string $envelopeBody): TransportResult
    {
        try {
            $request = $this->requestFactory->createRequest('POST', $this->dsn->getEnvelopeUrl())
                ->withHeader('Content-Type', 'application/x-beacon-envelope')
                ->withHeader('User-Agent', $this->userAgent)
                ->withHeader('X-Beacon-Auth', $this->dsn->getBeaconAuthHeader())
                ->withBody($this->streamFactory->createStream($envelopeBody));

            $response = $this->httpClient->sendRequest($request);
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

    private static function header(ResponseInterface $response, string $name): ?string
    {
        $values = $response->getHeader($name);

        return $values[0] ?? null;
    }
}
