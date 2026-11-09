<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class FakePsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly ResponseInterface|Throwable|null $next = null)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->next instanceof Throwable) {
            throw $this->next;
        }

        return $this->next ?? new Response(200);
    }
}
