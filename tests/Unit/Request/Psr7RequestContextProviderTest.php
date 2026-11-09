<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Request;

use Nowo\Beacon\Request\Psr7RequestContextProvider;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class Psr7RequestContextProviderTest extends TestCase
{
    public function testSnapshotFromServerRequest(): void
    {
        $provider = new Psr7RequestContextProvider();
        self::assertNull($provider->getRequest());

        $request = (new ServerRequest('get', 'https://api.example/v1/items?page=2&token=t', ['User-Agent' => 'UA', 'Accept' => 'application/json'], null, '1.1', ['REMOTE_ADDR' => '198.51.100.7']))
            ->withQueryParams(['page' => '2', 'token' => 't'])
            ->withAttribute('__route__', 'items.list');
        $provider->setRequest($request);

        $snapshot = $provider->getRequest();
        self::assertNotNull($snapshot);
        self::assertSame('https://api.example/v1/items?page=2&token=t', $snapshot->url);
        self::assertSame('GET', $snapshot->method);
        self::assertSame('page=2&token=t', $snapshot->queryString);
        self::assertSame('items.list', $snapshot->route);
        self::assertSame(['page', 'token'], $snapshot->queryKeys);
        self::assertSame('198.51.100.7', $snapshot->clientIp);
        self::assertSame('UA', $snapshot->userAgent);
        self::assertSame('application/json', $snapshot->headers['accept']);
        self::assertSame('api.example', $snapshot->headers['host']);

        $provider->clear();
        self::assertNull($provider->getRequest());
    }

    public function testCustomRouteResolverAndBareRequest(): void
    {
        $provider = new Psr7RequestContextProvider(static fn (ServerRequestInterface $request): string => 'resolved:' . $request->getMethod());
        $provider->setRequest(new ServerRequest('POST', '/x'));
        $snapshot = $provider->getRequest();
        self::assertNotNull($snapshot);
        self::assertSame('resolved:POST', $snapshot->route);
        self::assertNull($snapshot->queryString);
        self::assertNull($snapshot->clientIp);

        $empty = new Psr7RequestContextProvider(static fn (): string => '');
        $empty->setRequest(new ServerRequest('GET', '/'));
        self::assertNull($empty->getRequest()?->route);

        $default = new Psr7RequestContextProvider();
        $default->setRequest((new ServerRequest('GET', '/'))->withAttribute('_route', 'home'));
        self::assertSame('home', $default->getRequest()?->route);
        $default->setRequest(null);
        self::assertNull($default->getRequest());
    }
}
