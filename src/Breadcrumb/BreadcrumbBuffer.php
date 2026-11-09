<?php

declare(strict_types=1);

namespace Nowo\Beacon\Breadcrumb;

use function array_slice;
use function count;
use function microtime;

/**
 * Breadcrumb trail attached to the next captured event/transaction (then cleared). Bounded to {@see MAX_ITEMS}.
 *
 * Not final so that framework adapters can extend it (BeaconBundle adds Symfony's `ResetInterface`).
 */
class BreadcrumbBuffer
{
    public const MAX_ITEMS = 50;

    /** @var list<array{timestamp: float, type: string, category: string, level: string, message: string, data: array<string, mixed>}> */
    private array $items = [];

    /**
     * Append a breadcrumb (oldest entries drop when the buffer exceeds the max size).
     *
     * @param array<string, mixed> $data
     */
    public function add(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = [],
        string $type = 'default',
    ): void {
        $this->items[] = [
            'timestamp' => microtime(true),
            'type'      => $type,
            'category'  => $category,
            'level'     => $level,
            'message'   => $message,
            'data'      => $data,
        ];

        if (count($this->items) > self::MAX_ITEMS) {
            $this->items = array_slice($this->items, -self::MAX_ITEMS);
        }
    }

    /**
     * Current breadcrumb items in insertion order.
     *
     * @return list<array{timestamp: float, type: string, category: string, level: string, message: string, data: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Remove all buffered breadcrumbs.
     */
    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * Same as {@see clear()} (worker reset).
     */
    public function reset(): void
    {
        $this->clear();
    }
}
