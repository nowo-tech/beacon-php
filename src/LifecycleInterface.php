<?php

declare(strict_types=1);

namespace Nowo\Beacon;

/**
 * Flush and per-request / per-job reset (long-running workers).
 */
interface LifecycleInterface
{
    /**
     * Send what is pending (queued envelopes, in-flight responses), waiting at most `$timeout` seconds
     * (default: the `shutdown_timeout` option).
     *
     * @return bool true when nothing is left pending
     */
    public function flush(?float $timeout = null): bool;

    /**
     * Forget request / job state: tags, user, extra, breadcrumbs, spans and the trace id. Sends nothing.
     */
    public function reset(): void;
}
