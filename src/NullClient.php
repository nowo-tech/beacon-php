<?php

declare(strict_types=1);

namespace Nowo\Beacon;

use Throwable;

/**
 * No-op client (what {@see Beacon::getClient()} returns before {@see Beacon::init()}).
 */
final class NullClient implements ClientInterface, CheckInCapableInterface, DeployCapableInterface, QueueSnapshotCapableInterface, LifecycleInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string
    {
        return null;
    }

    public function captureMessage(string $message, string $level = 'error', array $extra = [], ?array $fingerprint = null): ?string
    {
        return null;
    }

    public function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
    }

    public function captureTransaction(string $transactionName, float $startTimestamp, float $endTimestamp, array $spans = [], array $extra = []): ?string
    {
        return null;
    }

    public function setTag(string $key, mixed $value): void
    {
    }

    public function setTags(array $tags): void
    {
    }

    public function getTags(): array
    {
        return [];
    }

    public function clearTags(): void
    {
    }

    public function captureScheduleCatalog(array $schedules): ?string
    {
        return null;
    }

    public function captureCheckIn(array $checkIn): ?string
    {
        return null;
    }

    public function captureDeploy(array $deploy): ?string
    {
        return null;
    }

    public function captureQueueSnapshot(array $transports): ?string
    {
        return null;
    }

    public function flush(?float $timeout = null): bool
    {
        return true;
    }

    public function reset(): void
    {
    }
}
