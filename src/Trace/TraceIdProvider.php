<?php

declare(strict_types=1);

namespace Nowo\Beacon\Trace;

use function bin2hex;
use function is_string;
use function preg_match;
use function random_bytes;
use function trim;

/**
 * Request / job scoped correlation id attached to every Beacon event (`tags.trace_id` and `extra.trace_id`).
 *
 * Not final so that framework adapters can extend it (BeaconBundle adds Symfony's `ResetInterface`).
 */
class TraceIdProvider
{
    public const HEADER = 'X-Beacon-Trace-Id';

    private ?string $traceId = null;

    /**
     * Current trace id, generating one when missing.
     */
    public function getOrCreate(): string
    {
        $this->traceId ??= self::generate();

        return $this->traceId;
    }

    /**
     * Current trace id or null when none was set/generated yet.
     */
    public function get(): ?string
    {
        return $this->traceId;
    }

    /**
     * Force a trace id (e.g. from an inbound header or a queue message). Invalid ids are ignored.
     */
    public function set(string $traceId): void
    {
        $normalized = self::normalize($traceId);
        if ($normalized !== null) {
            $this->traceId = $normalized;
        }
    }

    /**
     * Forget the current id (worker reset).
     */
    public function reset(): void
    {
        $this->traceId = null;
    }

    /**
     * @return non-empty-string
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Trimmed id when it is 8–128 characters of `[A-Za-z0-9._-]`, else null.
     */
    public static function normalize(string $traceId): ?string
    {
        $traceId = trim($traceId);
        if (preg_match('/^[A-Za-z0-9._-]{8,128}$/', $traceId) !== 1) {
            return null;
        }

        return $traceId;
    }

    /**
     * {@see normalize()} for an untyped value (null for non-strings).
     */
    public static function fromMixed(mixed $value): ?string
    {
        return is_string($value) ? self::normalize($value) : null;
    }
}
