<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Request;

use Nowo\Beacon\Request\GlobalsRequestContextProvider;
use PHPUnit\Framework\TestCase;

final class GlobalsRequestContextProviderTest extends TestCase
{
    public function testBuildsSnapshotFromServerArray(): void
    {
        $provider = new GlobalsRequestContextProvider(static fn (): array => [
            'REQUEST_METHOD'       => 'post',
            'REQUEST_URI'          => '/pay?token=abc&page=2',
            'QUERY_STRING'         => 'token=abc&page=2',
            'HTTPS'                => 'on',
            'HTTP_HOST'            => 'shop.example:8443',
            'HTTP_USER_AGENT'      => 'UA/1',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'CONTENT_TYPE'         => 'application/json',
            'CONTENT_LENGTH'       => '12',
            'REMOTE_ADDR'          => '198.51.100.7',
            'argv'                 => ['x'],
            0                      => 'numeric',
        ]);

        $request = $provider->getRequest();
        self::assertNotNull($request);
        self::assertSame('https://shop.example:8443/pay?token=abc&page=2', $request->url);
        self::assertSame('POST', $request->method);
        self::assertSame('token=abc&page=2', $request->queryString);
        self::assertSame(['token', 'page'], $request->queryKeys);
        self::assertSame('198.51.100.7', $request->clientIp);
        self::assertSame('UA/1', $request->userAgent);
        self::assertSame('application/json', $request->headers['content-type']);
        self::assertSame('12', $request->headers['content-length']);
        self::assertSame('1.2.3.4', $request->headers['x-forwarded-for']);
    }

    public function testFallbacksAndCli(): void
    {
        $plain = (new GlobalsRequestContextProvider(static fn (): array => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => 'health?x=1', 'HTTPS' => 'off', 'SERVER_NAME' => 'internal']))->getRequest();
        self::assertNotNull($plain);
        self::assertSame('http://internal/health?x=1', $plain->url);
        self::assertSame('x=1', $plain->queryString);
        self::assertNull($plain->clientIp);

        $bare = (new GlobalsRequestContextProvider(static fn (): array => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'QUERY_STRING' => '']))->getRequest();
        self::assertNotNull($bare);
        self::assertSame('http://localhost/', $bare->url);
        self::assertNull($bare->queryString);
        self::assertSame([], $bare->queryKeys);

        self::assertNull((new GlobalsRequestContextProvider(static fn (): array => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x?']))->getRequest()?->queryString);
        self::assertNull((new GlobalsRequestContextProvider(static fn (): array => ['argv' => []]))->getRequest());
        self::assertNull((new GlobalsRequestContextProvider(static fn (): array => ['REQUEST_METHOD' => '', 'REQUEST_URI' => '/']))->getRequest());
        // PHPUnit runs in the CLI: the default reads $_SERVER, which has no request.
        self::assertNull((new GlobalsRequestContextProvider())->getRequest());
    }
}
