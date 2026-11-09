<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Fixtures;

use Nowo\Beacon\Request\RequestContextProviderInterface;
use Nowo\Beacon\Request\RequestSnapshot;

final class StaticRequestProvider implements RequestContextProviderInterface
{
    public function __construct(public ?RequestSnapshot $request = null)
    {
    }

    public function getRequest(): ?RequestSnapshot
    {
        return $this->request;
    }
}
