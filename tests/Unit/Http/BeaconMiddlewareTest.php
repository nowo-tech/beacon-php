<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Http;

use Nowo\Beacon\Http\BeaconMiddleware;
use Nowo\Beacon\NullClient;
use Nowo\Beacon\Request\Psr7RequestContextProvider;
use Nowo\Beacon\Tests\Fixtures\RecordingClient;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class BeaconMiddlewareTest extends TestCase
{
    public function testExposesRequestDuringHandlingAndClearsIt(): void
    {
        $provider = new Psr7RequestContextProvider();
        $client   = new RecordingClient();
        $handler  = new class($provider) implements RequestHandlerInterface {
            public ?string $seen = null;

            public function __construct(private readonly Psr7RequestContextProvider $provider)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $this->provider->getRequest()?->url;

                return new Response(204);
            }
        };

        $response = (new BeaconMiddleware($client, $provider, true))->process(new ServerRequest('GET', 'https://h/x'), $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://h/x', $handler->seen);
        self::assertNull($provider->getRequest());
        self::assertSame([null], $client->flushes);
        self::assertSame(1, $client->resets);
    }

    public function testCapturesAndRethrows(): void
    {
        $provider = new Psr7RequestContextProvider();
        $client   = new RecordingClient();
        $handler  = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('handler failed');
            }
        };

        try {
            (new BeaconMiddleware($client, $provider))->process(new ServerRequest('GET', '/'), $handler);
            self::fail('Expected exception');
        } catch (RuntimeException $exception) {
            self::assertSame('handler failed', $exception->getMessage());
        }

        self::assertSame('handler failed', $client->events[0]['message']);
        self::assertNull($provider->getRequest());
        self::assertSame([], $client->flushes);

        // Non-lifecycle clients are never flushed.
        $ok = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        self::assertSame(200, (new BeaconMiddleware(new NullClient(), $provider, true))->process(new ServerRequest('GET', '/'), $ok)->getStatusCode());
    }
}
