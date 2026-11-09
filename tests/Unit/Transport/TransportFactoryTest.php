<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Transport;

use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Nowo\Beacon\Exception\MissingHttpClientException;
use Nowo\Beacon\Options;
use Nowo\Beacon\Tests\Fixtures\FakePsr18Client;
use Nowo\Beacon\Tests\Fixtures\RecordingTransport;
use Nowo\Beacon\Transport\NullTransport;
use Nowo\Beacon\Transport\Psr18Transport;
use Nowo\Beacon\Transport\QueuedTransport;
use Nowo\Beacon\Transport\SymfonyHttpClientTransport;
use Nowo\Beacon\Transport\TransportFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

use function in_array;

final class TransportFactoryTest extends TestCase
{
    private const DSN = 'https://pk:sk@b.example/1';

    public function testDisabledGivesNullTransport(): void
    {
        self::assertInstanceOf(NullTransport::class, (new TransportFactory())->create(Options::fromArray(['dsn' => ''])));
        self::assertInstanceOf(NullTransport::class, (new TransportFactory())->create(Options::fromArray(['dsn' => self::DSN, 'enabled' => false])));
    }

    public function testExplicitTransportAndQueueWrapping(): void
    {
        $transport = new RecordingTransport();
        self::assertSame($transport, (new TransportFactory())->create(Options::fromArray(['dsn' => self::DSN, 'transport' => $transport])));

        $queued = (new TransportFactory())->create(Options::fromArray(['dsn' => self::DSN, 'transport' => $transport, 'queue' => ['max_envelopes' => 3]]));
        self::assertInstanceOf(QueuedTransport::class, $queued);

        $already = new QueuedTransport($transport);
        self::assertSame($already, (new TransportFactory())->create(Options::fromArray(['dsn' => self::DSN, 'transport' => $already, 'queue' => true])));
    }

    public function testExplicitHttpClients(): void
    {
        $factory = new Psr17Factory();
        self::assertInstanceOf(SymfonyHttpClientTransport::class, (new TransportFactory())->create(Options::fromArray(['dsn' => self::DSN, 'http_client' => new MockHttpClient()])));
        self::assertInstanceOf(Psr18Transport::class, (new TransportFactory())->create(Options::fromArray(['dsn' => self::DSN, 'http_client' => new FakePsr18Client(), 'request_factory' => $factory, 'stream_factory' => $factory])));
        // Symfony's Psr18Client is its own request / stream factory.
        self::assertInstanceOf(Psr18Transport::class, (new TransportFactory())->create(Options::fromArray(['dsn' => self::DSN, 'http_client' => new Psr18Client(new MockHttpClient())])));
        // Factories discovered with php-http/discovery.
        self::assertInstanceOf(Psr18Transport::class, (new TransportFactory())->create(Options::fromArray(['dsn' => self::DSN, 'http_client' => new FakePsr18Client()])));
    }

    public function testAutoDetectionOrder(): void
    {
        $options = Options::fromArray(['dsn' => self::DSN, 'timeout' => 3]);

        self::assertInstanceOf(SymfonyHttpClientTransport::class, (new TransportFactory())->create($options));
        self::assertInstanceOf(Psr18Transport::class, $this->factoryWithout([HttpClient::class])->create($options));
        self::assertInstanceOf(Psr18Transport::class, $this->factoryWithout([HttpClient::class, GuzzleClient::class])->create($options));
    }

    public function testMissingClientAndFactories(): void
    {
        try {
            $this->factoryWithout([HttpClient::class, GuzzleClient::class, Psr18ClientDiscovery::class])->create(Options::fromDsn(self::DSN));
            self::fail('Expected MissingHttpClientException');
        } catch (MissingHttpClientException $exception) {
            self::assertStringContainsString('No HTTP client for Beacon', $exception->getMessage());
            self::assertStringNotContainsString('sk', $exception->getMessage());
        }

        $this->expectException(MissingHttpClientException::class);
        $this->expectExceptionMessage('PSR-17 factories');
        $this->factoryWithout([Psr17FactoryDiscovery::class])->create(Options::fromArray(['dsn' => self::DSN, 'http_client' => new FakePsr18Client()]));
    }

    public function testMissingStreamFactory(): void
    {
        $this->expectException(MissingHttpClientException::class);
        $this->factoryWithout([Psr17FactoryDiscovery::class])->create(Options::fromArray(['dsn' => self::DSN, 'http_client' => new FakePsr18Client(), 'request_factory' => new Psr17Factory()]));
    }

    public function testDiscoveryFailureIsWrapped(): void
    {
        $strategies = Psr18ClientDiscovery::getStrategies();
        Psr18ClientDiscovery::setStrategies([]);
        try {
            $this->expectException(MissingHttpClientException::class);
            $this->factoryWithout([HttpClient::class, GuzzleClient::class])->create(Options::fromDsn(self::DSN));
        } finally {
            Psr18ClientDiscovery::setStrategies($strategies);
        }
    }

    /**
     * @param list<string> $missing
     */
    private function factoryWithout(array $missing): TransportFactory
    {
        return new TransportFactory(static fn (string $class): bool => !in_array($class, $missing, true) && class_exists($class));
    }
}
