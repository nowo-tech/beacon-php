<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

/**
 * Delivers encoded Envelope bodies to Beacon ingest.
 *
 * Implementations never throw from {@see send()} or {@see flush()} and never put the secret anywhere but the
 * `X-Beacon-Auth` header (not in the URL, logs or error messages).
 */
interface TransportInterface
{
    /**
     * POST (or queue) one Envelope body.
     */
    public function send(string $envelopeBody): TransportResult;

    /**
     * Complete pending work (queued bodies, in-flight responses), waiting at most `$timeout` seconds.
     *
     * @return bool true when nothing is left pending
     */
    public function flush(float $timeout): bool;
}
