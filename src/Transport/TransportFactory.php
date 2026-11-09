<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory as GuzzleHttpFactory;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Exception\MissingHttpClientException;
use Nowo\Beacon\Options;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClientInterface;
use Throwable;

use function class_exists;

/**
 * Picks the transport for {@see Options}.
 *
 * 1. `transport` option: used as is.
 * 2. `http_client` option: a PSR-18 client → {@see Psr18Transport} (request / stream factories from the options, from
 *    the client itself when it implements them, else php-http/discovery); a Symfony `HttpClientInterface` →
 *    {@see SymfonyHttpClientTransport}.
 * 3. Auto-detection, preferring clients whose timeout and redirect policy beacon-php can enforce:
 *    symfony/http-client (`timeout` + `max_duration`, no redirects) → guzzlehttp/guzzle (`timeout` +
 *    `connect_timeout`, no redirects) → php-http/discovery (enforced only when the discovered client is Guzzle or
 *    Symfony's `Psr18Client`; any other client keeps its own timeout and redirect policy, see
 *    {@see Psr18Transport}) → {@see MissingHttpClientException}.
 *
 * With `queue` enabled the result is wrapped in a bounded {@see QueuedTransport}.
 */
final readonly class TransportFactory
{
    /** @var Closure(string): bool */
    private Closure $classExists;

    /**
     * @param (Closure(string): bool)|null $classExists class probe (tests)
     */
    public function __construct(?Closure $classExists = null)
    {
        $this->classExists = $classExists ?? class_exists(...);
    }

    /**
     * @throws MissingHttpClientException
     */
    public function create(Options $options): TransportInterface
    {
        $dsn = $options->dsn;
        if (!$options->isActive() || !$dsn instanceof Dsn) {
            return new NullTransport();
        }

        $transport = $options->transport ?? $this->createHttpTransport($options, $dsn);

        if ($options->queue !== null && !$transport instanceof QueuedTransport) {
            $transport = new QueuedTransport(
                $transport,
                $options->queue['max_envelopes'],
                $options->queue['max_bytes'],
            );
        }

        return $transport;
    }

    private function createHttpTransport(Options $options, Dsn $dsn): TransportInterface
    {
        $client = $options->httpClient;

        if ($client instanceof SymfonyHttpClientInterface) {
            return $this->symfony($client, $options, $dsn);
        }

        if ($client instanceof Psr18ClientInterface) {
            return new Psr18Transport(
                $client,
                $options->requestFactory ?? ($client instanceof RequestFactoryInterface ? $client : $this->discoverRequestFactory()),
                $options->streamFactory ?? ($client instanceof StreamFactoryInterface ? $client : $this->discoverStreamFactory()),
                $dsn,
                $options->logger,
                timeout: $options->timeout,
            );
        }

        if (($this->classExists)(HttpClient::class)) {
            return $this->symfony(HttpClient::create(), $options, $dsn);
        }

        if (($this->classExists)(GuzzleClient::class) && ($this->classExists)(GuzzleHttpFactory::class)) {
            $factory = new GuzzleHttpFactory();

            return new Psr18Transport(
                new GuzzleClient([
                    'timeout'         => $options->timeout,
                    'connect_timeout' => $options->timeout,
                    'verify'          => $options->verifyPeer,
                    'http_errors'     => false,
                    'allow_redirects' => false,
                ]),
                $options->requestFactory ?? $factory,
                $options->streamFactory ?? $factory,
                $dsn,
                $options->logger,
                timeout: $options->timeout,
            );
        }

        if (($this->classExists)(Psr18ClientDiscovery::class)) {
            try {
                $discovered = Psr18ClientDiscovery::find();
            } catch (Throwable $exception) {
                throw new MissingHttpClientException(self::MISSING, 0, $exception);
            }

            return new Psr18Transport(
                $discovered,
                $options->requestFactory ?? $this->discoverRequestFactory(),
                $options->streamFactory ?? $this->discoverStreamFactory(),
                $dsn,
                $options->logger,
                timeout: $options->timeout,
            );
        }

        throw new MissingHttpClientException(self::MISSING);
    }

    private function symfony(SymfonyHttpClientInterface $client, Options $options, Dsn $dsn): SymfonyHttpClientTransport
    {
        return new SymfonyHttpClientTransport(
            $client,
            $dsn,
            $options->verifyPeer,
            $options->timeout,
            $options->logger,
            async: $options->async,
        );
    }

    private function discoverRequestFactory(): RequestFactoryInterface
    {
        if (!($this->classExists)(Psr17FactoryDiscovery::class)) {
            throw new MissingHttpClientException('A PSR-18 client needs PSR-17 factories: pass "request_factory" and "stream_factory", or install php-http/discovery.');
        }

        return Psr17FactoryDiscovery::findRequestFactory();
    }

    private function discoverStreamFactory(): StreamFactoryInterface
    {
        if (!($this->classExists)(Psr17FactoryDiscovery::class)) {
            throw new MissingHttpClientException('A PSR-18 client needs PSR-17 factories: pass "request_factory" and "stream_factory", or install php-http/discovery.');
        }

        return Psr17FactoryDiscovery::findStreamFactory();
    }

    private const MISSING = 'No HTTP client for Beacon: install symfony/http-client or guzzlehttp/guzzle (or php-http/discovery with a PSR-18 client), or pass the "http_client" / "transport" option.';
}
