<?php

declare(strict_types=1);

namespace Nowo\Beacon\Http;

use Nowo\Beacon\ClientInterface;
use Nowo\Beacon\LifecycleInterface;
use Nowo\Beacon\Request\Psr7RequestContextProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * PSR-15 middleware (requires `psr/http-server-middleware`): exposes the request to the client, captures a
 * Throwable that escapes the inner handler (and rethrows it), and clears the request afterwards.
 *
 * With `$resetAfterRequest` (long-running servers), the client is flushed and its scope reset after every request so
 * tags, user and breadcrumbs never leak into the next one.
 */
final readonly class BeaconMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ClientInterface $client,
        private Psr7RequestContextProvider $requestProvider,
        private bool $resetAfterRequest = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->requestProvider->setRequest($request);

        try {
            return $handler->handle($request);
        } catch (Throwable $throwable) {
            $this->client->captureException($throwable);

            throw $throwable;
        } finally {
            if ($this->resetAfterRequest && $this->client instanceof LifecycleInterface) {
                $this->client->flush();
                $this->client->reset();
            }
            $this->requestProvider->clear();
        }
    }
}
