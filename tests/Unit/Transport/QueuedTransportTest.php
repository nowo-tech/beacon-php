<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Transport;

use Nowo\Beacon\Tests\Fixtures\RecordingTransport;
use Nowo\Beacon\Transport\QueuedTransport;
use Nowo\Beacon\Transport\TransportResult;
use PHPUnit\Framework\TestCase;

final class QueuedTransportTest extends TestCase
{
    private float $now = 1000.0;

    public function testSendOnlyQueuesAndFlushDrainsInOrder(): void
    {
        $inner = new RecordingTransport();
        $queue = new QueuedTransport($inner, now: fn (): float => $this->now);

        self::assertTrue($queue->send('a')->queued);
        $queue->send('b');
        self::assertSame([], $inner->bodies);
        self::assertSame(2, $queue->count());

        self::assertTrue($queue->flush(1.0));
        self::assertSame(['a', 'b'], $inner->bodies);
        self::assertSame(0, $queue->count());
        self::assertSame([1.0], $inner->flushes);
    }

    public function testBoundsByCountAndBytes(): void
    {
        $queue = new QueuedTransport(new RecordingTransport(), maxEnvelopes: 2, maxBytes: 10, now: fn (): float => $this->now);

        $queue->send('1111');
        $queue->send('2222');
        $queue->send('3333');
        self::assertSame(2, $queue->count());
        self::assertSame(1, $queue->getDroppedCount());

        $queue->send('44444444');
        self::assertSame(1, $queue->count());

        self::assertFalse($queue->send('this body is too large')->isAccepted());
        self::assertSame(4, $queue->getDroppedCount());
    }

    public function testRateLimitKeepsBodyAndPauses(): void
    {
        $inner          = new RecordingTransport();
        $inner->results = [new TransportResult(false, 429, null, 30.0), new TransportResult(false, 500), new TransportResult(false, 503)];
        $queue          = new QueuedTransport($inner, maxRetryAfter: 60.0, maxAttempts: 3, now: fn (): float => $this->now);
        $queue->send('a');
        $queue->send('b');

        self::assertFalse($queue->flush(5.0));
        self::assertSame(['a'], $inner->bodies);
        self::assertSame(1030.0, $queue->getBlockedUntil());
        self::assertSame(2, $queue->count());

        // Still paused: nothing is sent.
        $queue->flush(5.0);
        self::assertCount(1, $inner->bodies);

        $this->now = 1031.0;
        $queue->flush(5.0);
        self::assertSame(['a', 'a'], $inner->bodies);
        self::assertSame(1033.0, $queue->getBlockedUntil());

        $this->now = 1040.0;
        $queue->flush(5.0);
        // Third attempt failed: dropped, pause grows (backoff 4 s).
        self::assertSame(['a', 'a', 'a'], $inner->bodies);
        self::assertSame(1, $queue->count());
        self::assertSame(1, $queue->getDroppedCount());
        self::assertSame(1044.0, $queue->getBlockedUntil());

        $this->now = 1050.0;
        self::assertTrue($queue->flush(5.0));
        self::assertSame('b', $inner->bodies[3]);
    }

    public function testAuthFailureDropsQueueAndPausesAnHour(): void
    {
        $inner          = new RecordingTransport();
        $inner->results = [new TransportResult(false, 401)];
        $queue          = new QueuedTransport($inner, now: fn (): float => $this->now);
        $queue->send('a');
        $queue->send('b');

        self::assertTrue($queue->flush(5.0));
        self::assertSame(0, $queue->count());
        self::assertSame(2, $queue->getDroppedCount());
        self::assertSame(1000.0 + QueuedTransport::AUTH_PAUSE_SECONDS, $queue->getBlockedUntil());

        $queue->clear();
        self::assertSame(0.0, $queue->getBlockedUntil());
    }

    public function testRejectedBodiesAreDroppedAndDeadlineStopsDrain(): void
    {
        $inner          = new RecordingTransport();
        $inner->results = [new TransportResult(false, 400)];
        $queue          = new QueuedTransport($inner, now: fn (): float => $this->now);
        $queue->send('bad');
        $queue->send('good');
        self::assertTrue($queue->flush(5.0));
        self::assertSame(['bad', 'good'], $inner->bodies);
        self::assertSame(1, $queue->getDroppedCount());

        $queue->send('late');
        self::assertFalse($queue->flush(0.0));
        self::assertSame(1, $queue->count());

        $inner->flushResult = false;
        $queue->clear();
        self::assertFalse($queue->flush(1.0));
    }

    public function testDefaultClock(): void
    {
        $queue = new QueuedTransport(new RecordingTransport());
        $queue->send('x');
        self::assertTrue($queue->flush(1.0));
    }
}
