<?php

declare(strict_types=1);

namespace Nowo\Beacon\Request;

/**
 * Framework-neutral facts about the current HTTP request, read by EnvelopeBuilder (option `send.request`).
 *
 * Values are raw: EnvelopeBuilder redacts the URL, the query string and the referer, and only sends the headers of
 * {@see EnvelopeBuilder::REQUEST_HEADERS}. The client IP is only used when `send.client` is enabled.
 */
final readonly class RequestSnapshot
{
    /**
     * @param string $url absolute URL (`scheme://host[:port]/path[?query]`)
     * @param string|null $queryString raw query string without `?` (null when there is none)
     * @param list<string> $queryKeys query parameter names (values are never sent here)
     * @param array<string, string> $headers lower-case header name → first value
     */
    public function __construct(
        public string $url,
        public string $method,
        public ?string $queryString = null,
        public ?string $route = null,
        public ?string $controller = null,
        public array $queryKeys = [],
        public ?string $clientIp = null,
        public ?string $userAgent = null,
        public array $headers = [],
    ) {
    }
}
