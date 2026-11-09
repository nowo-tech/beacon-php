<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Fixtures;

use Nowo\Beacon\ClientInterface;
use Throwable;

/**
 * A client with only the base interface (no flush / reset / capabilities).
 */
class NonLifecycleClient implements ClientInterface
{
    public function isEnabled(): bool
    {
        return true;
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
}
