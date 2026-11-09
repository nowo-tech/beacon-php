<?php

declare(strict_types=1);

namespace Nowo\Beacon\Request;

/**
 * Supplies the current HTTP request, or null outside a request (CLI, queue worker between jobs).
 *
 * Implementations must not keep a request after it ended: a long-running worker would attach it to the next job.
 */
interface RequestContextProviderInterface
{
    public function getRequest(): ?RequestSnapshot;
}
