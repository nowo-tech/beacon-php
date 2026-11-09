<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Transport;

use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Tests\Fixtures\ArrayLogger;
use Nowo\Beacon\Tests\Fixtures\FakePsr18Client;
use Nowo\Beacon\Transport\Psr18Transport;
use Nowo\Beacon\Version;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

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
}
