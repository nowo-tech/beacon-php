<?php

declare(strict_types=1);

namespace Nowo\Beacon;

use Throwable;

/**
 * Public API for reporting events and transactions to a Beacon instance.
 *
 * Same methods and semantics as BeaconBundle's `BeaconClientInterface`, so the bundle's client can delegate to
 * {@see Client}. Optional capabilities live in {@see CheckInCapableInterface}, {@see DeployCapableInterface},
 * {@see QueueSnapshotCapableInterface} and {@see LifecycleInterface}.
 */
interface ClientInterface
{
    /**
     * Whether the client will attempt to send envelopes.
     */
    public function isEnabled(): bool;

    /**
     * Capture an exception and send it as an Envelope event item.
     *
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     *
     * @return string|null Event id (null when disabled or dropped)
     */
    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string;

    /**
     * Capture a free-form message.
     *
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     *
     * @return string|null Event id (null when disabled or dropped)
     */
    public function captureMessage(
        string $message,
        string $level = 'error',
        array $extra = [],
        ?array $fingerprint = null,
    ): ?string;

    /**
     * Record a breadcrumb for the next captured event/transaction.
     *
     * @param array<string, mixed> $data
     */
    public function addBreadcrumb(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = [],
    ): void;

    /**
     * Capture a performance transaction (Beacon envelope item type `transaction`).
     *
     * @param list<array{
     *     op?: string,
     *     description?: string,
     *     span_id?: string,
     *     start_timestamp?: float,
     *     timestamp?: float
     * }> $spans
     * @param array<string, mixed> $extra
     *
     * @return string|null Event id (null when disabled or dropped)
     */
    public function captureTransaction(
        string $transactionName,
        float $startTimestamp,
        float $endTimestamp,
        array $spans = [],
        array $extra = [],
    ): ?string;

    /**
     * Set a single public tag on the current scope (merged; overwrites same key).
     */
    public function setTag(string $key, mixed $value): void;

    /**
     * Merge public tags into the current scope.
     *
     * @param array<string, mixed> $tags
     */
    public function setTags(array $tags): void;

    /**
     * Current scope tags.
     *
     * @return array<string, string>
     */
    public function getTags(): array;

    /**
     * Clear all scope tags.
     */
    public function clearTags(): void;
}
