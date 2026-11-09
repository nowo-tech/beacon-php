<?php

declare(strict_types=1);

namespace Nowo\Beacon;

/**
 * Clients that can send queue snapshots (`queue_snapshot` Envelope items: message counts per transport).
 */
interface QueueSnapshotCapableInterface
{
    /**
     * @param list<array{name: string, count: int|null, failure: bool}> $transports
     *
     * @return string|null Event id when sent (null when disabled)
     */
    public function captureQueueSnapshot(array $transports): ?string;
}
