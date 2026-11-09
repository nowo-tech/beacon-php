<?php

declare(strict_types=1);

namespace Nowo\Beacon\Request;

use Closure;

use function array_keys;
use function explode;
use function is_string;
use function parse_str;
use function str_replace;
use function strtolower;
use function substr;

/**
 * Reads the current request from `$_SERVER` (plain PHP, PHP-FPM, FrankenPHP: the superglobals are refreshed per
 * request). Returns null when `REQUEST_METHOD` / `REQUEST_URI` are missing (CLI).
 *
 * The client IP is taken from `REMOTE_ADDR` only (forwarded headers are spoofable) and is only sent when
 * `send.client` is enabled. Event loops that keep `$_SERVER` across requests (Swoole, RoadRunner, ReactPHP) should
 * use {@see Psr7RequestContextProvider} instead.
 */
final readonly class GlobalsRequestContextProvider implements RequestContextProviderInterface
{
    /** @var Closure(): array<array-key, mixed> */
    private Closure $server;

    /**
     * @param (Closure(): array<array-key, mixed>)|null $server returns the server array (default: `$_SERVER`, read at call time)
     */
    public function __construct(?Closure $server = null)
    {
        $this->server = $server ?? static fn (): array => $_SERVER;
    }

    public function getRequest(): ?RequestSnapshot
    {
        $server = ($this->server)();
        $method = $server['REQUEST_METHOD'] ?? null;
        $uri    = $server['REQUEST_URI'] ?? null;
        if (!is_string($method) || $method === '' || !is_string($uri) || $uri === '') {
            return null;
        }

        $https  = $server['HTTPS'] ?? '';
        $scheme = is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        $host   = $this->string($server, 'HTTP_HOST') ?? $this->string($server, 'SERVER_NAME') ?? 'localhost';
        $path   = $uri[0] === '/' ? $uri : '/' . $uri;

        $queryString = $this->string($server, 'QUERY_STRING');
        if ($queryString === null) {
            $parts       = explode('?', $path, 2);
            $queryString = $parts[1] ?? null;
        }
        if ($queryString === '') {
            $queryString = null;
        }

        $queryKeys = [];
        if ($queryString !== null) {
            parse_str($queryString, $query);
            foreach (array_keys($query) as $key) {
                $queryKeys[] = (string) $key;
            }
        }

        $headers = [];
        foreach ($server as $name => $value) {
            $name = (string) $name;
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($name, 'HTTP_')) {
                $headers[$this->headerName(substr($name, 5))] = $value;
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $headers[$this->headerName($name)] = $value;
            }
        }

        return new RequestSnapshot(
            url: $scheme . '://' . $host . $path,
            method: strtoupper($method),
            queryString: $queryString,
            queryKeys: $queryKeys,
            clientIp: $this->string($server, 'REMOTE_ADDR'),
            userAgent: $headers['user-agent'] ?? null,
            headers: $headers,
        );
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function string(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function headerName(string $serverKey): string
    {
        return strtolower(str_replace('_', '-', $serverKey));
    }
}
