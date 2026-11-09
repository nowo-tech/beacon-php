<?php

declare(strict_types=1);

namespace Nowo\Beacon\Request;

use Closure;
use Nowo\Beacon\Http\BeaconMiddleware;
use Psr\Http\Message\ServerRequestInterface;

use function array_keys;
use function is_string;
use function strtolower;
use function strtoupper;

/**
 * Holds the PSR-7 request of the request being handled (Slim, Mezzio, any PSR-15 stack, event loops).
 *
 * {@see BeaconMiddleware} sets and clears it around each request; call {@see setRequest()} /
 * {@see clear()} yourself elsewhere. The client IP comes from the `REMOTE_ADDR` server param only.
 */
final class Psr7RequestContextProvider implements RequestContextProviderInterface
{
    private ?ServerRequestInterface $request = null;

    /**
     * @param (Closure(ServerRequestInterface): ?string)|null $routeResolver route name / pattern for a request
     *                                                                       (default: the `_route` or `__route__` string attribute)
     */
    public function __construct(
        private readonly ?Closure $routeResolver = null,
    ) {
    }

    public function setRequest(?ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function clear(): void
    {
        $this->request = null;
    }

    public function getRequest(): ?RequestSnapshot
    {
        $request = $this->request;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $uri   = $request->getUri();
        $query = $uri->getQuery();

        $queryKeys = [];
        foreach (array_keys($request->getQueryParams()) as $key) {
            $queryKeys[] = (string) $key;
        }

        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $request->getHeaderLine((string) $name);
        }

        $server   = $request->getServerParams();
        $clientIp = $server['REMOTE_ADDR'] ?? null;
        $route    = $this->routeResolver instanceof Closure ? ($this->routeResolver)($request) : $this->defaultRoute($request);

        return new RequestSnapshot(
            url: (string) $uri,
            method: strtoupper($request->getMethod()),
            queryString: $query !== '' ? $query : null,
            route: $route !== '' ? $route : null,
            queryKeys: $queryKeys,
            clientIp: is_string($clientIp) && $clientIp !== '' ? $clientIp : null,
            userAgent: $headers['user-agent'] ?? null,
            headers: $headers,
        );
    }

    private function defaultRoute(ServerRequestInterface $request): ?string
    {
        foreach (['_route', '__route__'] as $attribute) {
            $value = $request->getAttribute($attribute);
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
