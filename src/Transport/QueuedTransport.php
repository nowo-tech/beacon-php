<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

use Closure;

use function array_shift;
use function array_unshift;
use function count;
use function max;
use function microtime;
use function min;
use function strlen;

/**
 * Deferred delivery (option `queue`): {@see send()} only appends to a bounded in-memory FIFO and {@see flush()}
 * sends, oldest first, until the queue is empty or the deadline passes.
 *
 * - Bounded: at most `$maxEnvelopes` bodies and `$maxBytes` bytes; the oldest are evicted (counted in
 *   {@see getDroppedCount()}); a single body larger than `$maxBytes` is refused.
 * - Rate limits: a 429 / 5xx / network failure puts the body back at the head and pauses sending for `Retry-After`
 *   (capped at `$maxRetryAfter` seconds) or an exponential backoff; a body is dropped after `$maxAttempts` attempts.
 * - 401 / 403: the queue is dropped and sending pauses for an hour (bad credentials would fail every time).
 *
 * Memory only: what is still queued when the process dies is lost. Call {@see flush()} at the end of each request or
 * job (the error handler's shutdown function does it with `shutdown_timeout`).
 */
final class QueuedTransport implements TransportInterface
{
    public const DEFAULT_MAX_ENVELOPES = 100;

    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    public const AUTH_PAUSE_SECONDS = 3600.0;

    /** @var list<array{body: string, attempts: int}> */
    private array $queue = [];

    private int $bytes = 0;

    private int $dropped = 0;

    private float $blockedUntil = 0.0;

    private int $failures = 0;

    /** @var Closure(): float */
    private readonly Closure $now;

    /**
     * @param (Closure(): float)|null $now current time in seconds (tests)
     */
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly int $maxEnvelopes = self::DEFAULT_MAX_ENVELOPES,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly float $maxRetryAfter = 60.0,
        private readonly int $maxAttempts = 3,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    public function send(string $envelopeBody): TransportResult
    {
        $size = strlen($envelopeBody);
        if ($size > $this->maxBytes) {
            ++$this->dropped;

            return new TransportResult(false, null, 'Envelope larger than the queue byte limit.');
        }

        $this->queue[] = ['body' => $envelopeBody, 'attempts' => 0];
        $this->bytes += $size;

        while (count($this->queue) > max(1, $this->maxEnvelopes) || $this->bytes > $this->maxBytes) {
            $this->evictOldest();
        }

        return TransportResult::queued();
    }

    public function flush(float $timeout): bool
    {
        $deadline = ($this->now)() + max(0.0, $timeout);

        while ($this->queue !== [] && ($this->now)() < $deadline && ($this->now)() >= $this->blockedUntil) {
            /** @var array{body: string, attempts: int} $entry */
            $entry = array_shift($this->queue);
            $this->bytes -= strlen($entry['body']);
            $entry = ['body' => $entry['body'], 'attempts' => $entry['attempts'] + 1];

            $result = $this->inner->send($entry['body']);
            if ($result->isAccepted()) {
                $this->failures = 0;

                continue;
            }

            if ($result->statusCode === 401 || $result->statusCode === 403) {
                $this->dropped += 1 + count($this->queue);
                $this->queue        = [];
                $this->bytes        = 0;
                $this->blockedUntil = ($this->now)() + self::AUTH_PAUSE_SECONDS;

                break;
            }

            if (!$result->isRetryable()) {
                ++$this->dropped;

                continue;
            }

            ++$this->failures;
            if ($entry['attempts'] < $this->maxAttempts) {
                array_unshift($this->queue, $entry);
                $this->bytes += strlen($entry['body']);
            } else {
                ++$this->dropped;
            }
            $this->blockedUntil = ($this->now)() + $this->retryDelay($result);

            break;
        }

        $remaining = max(0.0, $deadline - ($this->now)());

        return $this->inner->flush($remaining) && $this->queue === [];
    }

    /**
     * Bodies waiting for {@see flush()}.
     */
    public function count(): int
    {
        return count($this->queue);
    }

    /**
     * Bodies dropped since construction (evicted, oversize, rejected, out of attempts, or dropped after a 401/403).
     */
    public function getDroppedCount(): int
    {
        return $this->dropped;
    }

    /**
     * Epoch seconds before which {@see flush()} does not send (0 when not paused).
     */
    public function getBlockedUntil(): float
    {
        return $this->blockedUntil;
    }

    /**
     * Forget queued bodies and pauses (worker reset; nothing is sent).
     */
    public function clear(): void
    {
        $this->queue        = [];
        $this->bytes        = 0;
        $this->blockedUntil = 0.0;
        $this->failures     = 0;
    }

    private function evictOldest(): void
    {
        $entry = array_shift($this->queue);
        if ($entry !== null) {
            $this->bytes -= strlen($entry['body']);
            ++$this->dropped;
        }
    }

    private function retryDelay(TransportResult $result): float
    {
        $backoff = min($this->maxRetryAfter, 2 ** min(10, $this->failures - 1));

        return min($this->maxRetryAfter, max($result->retryAfter ?? 0.0, $backoff));
    }
}
