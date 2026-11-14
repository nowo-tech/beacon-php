<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Transport;

use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Tests\Fixtures\ArrayLogger;
use Nowo\Beacon\Transport\SymfonyHttpClientTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class SymfonyHttpClientTransportTest extends TestCase
{
    public function testSyncRequestMatchesBundleOptions(): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });
        $transport = new SymfonyHttpClientTransport($client, (new DsnParser())->parse('https://pk:sk@b.example/1'), false, 2.5);

        self::assertTrue($transport->send('body')->isAccepted());
        self::assertSame('POST', $seen['method']);
        self::assertSame('https://b.example/api/ingest/v1/1/envelope/', $seen['url']);
        $headers = implode("\n", $seen['options']['headers']);
        self::assertStringContainsString('X-Beacon-Auth: Beacon beacon_key=pk, beacon_secret=sk', $headers);
        self::assertStringContainsString('Content-Type: application/x-beacon-envelope', $headers);
        self::assertSame(2.5, $seen['options']['timeout']);
        self::assertSame(2.5, $seen['options']['max_duration']);
        self::assertSame(0, $seen['options']['max_redirects']);
        self::assertFalse($seen['options']['verify_peer']);
        self::assertFalse($seen['options']['verify_host']);
    }

    public function testNullRedirectPolicyKeepsClientDefault(): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('', ['http_code' => 200]);
        });
        (new SymfonyHttpClientTransport($client, (new DsnParser())->parse('https://pk:sk@b.example/1'), maxRedirects: null))->send('b');

        self::assertArrayNotHasKey('max_redirects', $seen);
        self::assertArrayNotHasKey('verify_peer', $seen);
    }

    public function testRateLimitHeadersAndFailures(): void
    {
        $dsn    = (new DsnParser())->parse('https://pk:sk@b.example/1');
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 9', 'X-Beacon-Rate-Limit: quota']]),
            new MockResponse('', ['error' => 'Could not resolve host']),
        ]);
        $logger    = new ArrayLogger();
        $transport = new SymfonyHttpClientTransport($client, $dsn, logger: $logger);

        $limited = $transport->send('a');
        self::assertSame(9.0, $limited->retryAfter);
        self::assertSame('quota', $limited->rateLimit);

        $failed = $transport->send('b');
        self::assertFalse($failed->isAccepted());
        self::assertNull($failed->statusCode);

        $throwing = new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new TransportException('refused');
            }

            public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new TransportException('unused');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
        $start = (new SymfonyHttpClientTransport($throwing, $dsn))->send('c');
        self::assertSame('refused', $start->getErrorMessage());
    }

    public function testHeaderReadFailureIsTolerated(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(429);
        $response->method('getHeaders')->willThrowException(new TransportException('broken'));
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $result = (new SymfonyHttpClientTransport($client, (new DsnParser())->parse('https://pk:sk@b.example/1')))->send('x');
        self::assertSame(429, $result->statusCode);
        self::assertNull($result->retryAfter);
    }

    public function testAsyncModeIsBoundedAndFlushHonoursTheDeadline(): void
    {
        $calls  = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 200]);
        });
        $transport = new SymfonyHttpClientTransport($client, (new DsnParser())->parse('https://pk:sk@b.example/1'), async: true, maxPending: 2);

        self::assertTrue($transport->send('1')->queued);
        $transport->send('2');
        $transport->send('3');
        self::assertSame(2, $transport->countPending());
        self::assertTrue($transport->flush(5.0));
        self::assertSame(0, $transport->countPending());
        self::assertSame(3, $calls);

        $transport->send('4');
        self::assertFalse($transport->flush(0.0));
        self::assertSame(0, $transport->countPending());
    }

    public function testUnflushedAsyncResponsesAreCompletedOnDestruction(): void
    {
        $logger    = new ArrayLogger();
        $client    = new MockHttpClient([new MockResponse('', ['http_code' => 401])]);
        $transport = new SymfonyHttpClientTransport($client, (new DsnParser())->parse('https://pk:sk@b.example/1'), logger: $logger, async: true);
        $transport->send('x');

        unset($transport);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('authentication rejected', $logger->records[0]['message']);
    }
}
