<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Transport;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Tests\Fixtures\ArrayLogger;
use Nowo\Beacon\Tests\Fixtures\FakePsr18Client;
use Nowo\Beacon\Transport\Psr18Transport;
use Nowo\Beacon\Transport\QueuedTransport;
use Nowo\Beacon\Version;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Psr18TransportTest extends TestCase
{
    public function testPostsEnvelopeWithAuthHeaderOnly(): void
    {
        $client    = new FakePsr18Client(new Response(200));
        $factory   = new Psr17Factory();
        $transport = new Psr18Transport($client, $factory, $factory, (new DsnParser())->parse('https://pk:sk@b.example:9447/1'));

        $result = $transport->send("a\nb\nc\n");

        self::assertTrue($result->isAccepted());
        self::assertTrue($transport->flush(1.0));
        $request = $client->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://b.example:9447/api/1/envelope/', (string) $request->getUri());
        self::assertSame('application/x-beacon-envelope', $request->getHeaderLine('Content-Type'));
        self::assertSame(Version::userAgent(), $request->getHeaderLine('User-Agent'));
        self::assertSame('Beacon beacon_key=pk, beacon_secret=sk', $request->getHeaderLine('X-Beacon-Auth'));
        self::assertSame("a\nb\nc\n", (string) $request->getBody());
    }

    public function testRateLimitAndNetworkErrors(): void
    {
        $factory = new Psr17Factory();
        $dsn     = (new DsnParser())->parse('https://pk:sk@b.example/1');

        $limited = (new Psr18Transport(new FakePsr18Client(new Response(429, ['Retry-After' => '7'])), $factory, $factory, $dsn, null, 'custom/1'))->send('x');
        self::assertSame(429, $limited->statusCode);
        self::assertSame(7.0, $limited->retryAfter);

        $network = new class('connection refused') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('POST', 'https://b.example');
            }
        };
        $logger = new ArrayLogger();
        $failed = (new Psr18Transport(new FakePsr18Client($network), $factory, $factory, $dsn, $logger))->send('x');
        self::assertFalse($failed->isAccepted());
        self::assertSame('connection refused', $failed->getErrorMessage());
        self::assertSame('Beacon ingest transport failed.', $logger->records[0]['message']);
    }

    public function testUnknownClientRedirectIsAFailedSendThatIsNeverResent(): void
    {
        $client    = new FakePsr18Client(new Response(307, ['Location' => 'https://other.example/steal']));
        $factory   = new Psr17Factory();
        $logger    = new ArrayLogger();
        $transport = new Psr18Transport($client, $factory, $factory, (new DsnParser())->parse('https://pk:sk@b.example/1'), $logger);

        $result = $transport->send('x');
        self::assertFalse($result->isAccepted());
        self::assertSame(307, $result->statusCode);
        self::assertSame('Redirect not followed.', $result->getErrorMessage());
        self::assertFalse($result->isRetryable());
        self::assertStringContainsString('redirect', $logger->records[0]['message']);

        $queue = new QueuedTransport($transport);
        $queue->send('y');
        $queue->flush(1.0);
        self::assertSame(0, $queue->count());
        self::assertCount(2, $client->requests);
    }

    public function testGuzzleClientNeverFollowsRedirectsAndGetsTheTimeout(): void
    {
        $mock      = new MockHandler([new Response(308, ['Location' => 'http://other.example/']), new Response(200)]);
        $guzzle    = new GuzzleClient(['handler' => HandlerStack::create($mock)]);   // default policy: follows redirects
        $factory   = new Psr17Factory();
        $transport = new Psr18Transport($guzzle, $factory, $factory, (new DsnParser())->parse('https://pk:sk@b.example/1'), timeout: 1.5);

        $result = $transport->send('x');
        self::assertSame(308, $result->statusCode);
        self::assertCount(1, $mock, 'the redirect target must not be requested');
        $options = $mock->getLastOptions();
        self::assertFalse($options['allow_redirects']);
        self::assertSame(1.5, $options['timeout']);
        self::assertSame(1.5, $options['connect_timeout']);

        $plain = new Psr18Transport(new GuzzleClient(['handler' => HandlerStack::create($mock)]), $factory, $factory, (new DsnParser())->parse('https://pk:sk@b.example/1'));
        self::assertTrue($plain->send('y')->isAccepted());
        self::assertArrayNotHasKey('connect_timeout', $mock->getLastOptions());
    }

    public function testSymfonyPsr18ClientIsWrappedWithoutRedirects(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse('', ['http_code' => 202]);
        });
        $dsn = (new DsnParser())->parse('https://pk:sk@b.example/1');

        self::assertTrue((new Psr18Transport(new Psr18Client($mock), new Psr17Factory(), new Psr17Factory(), $dsn, timeout: 2.0))->send('x')->isAccepted());
        self::assertSame(0, $seen[0]['max_redirects']);
        self::assertSame(2.0, $seen[0]['timeout']);
        self::assertSame(2.0, $seen[0]['max_duration']);

        (new Psr18Transport(new Psr18Client($mock), new Psr17Factory(), new Psr17Factory(), $dsn))->send('y');
        self::assertSame(0, $seen[1]['max_redirects']);
        self::assertNotSame(2.0, $seen[1]['timeout'] ?? null);
    }
}
