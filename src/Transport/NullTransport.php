<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

/**
 * Discards every envelope (disabled client).
 */
final class NullTransport implements TransportInterface
{
    public function send(string $envelopeBody): TransportResult
    {
        return new TransportResult(false, null, 'Beacon is disabled.');
    }

    public function flush(float $timeout): bool
    {
        return true;
    }
}
