<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Transport;

use Nowo\Beacon\Transport\NullTransport;
use Nowo\Beacon\Transport\TransportResult;
use PHPUnit\Framework\TestCase;

final class TransportResultTest extends TestCase
{
    public function testAccessorsAndRetryability(): void
    {
        $ok = new TransportResult(true, 202);
        self::assertTrue($ok->isAccepted());
        self::assertSame(202, $ok->getStatusCode());
        self::assertNull($ok->getErrorMessage());
        self::assertFalse($ok->isRetryable());

        self::assertTrue(TransportResult::queued()->queued);
        self::assertTrue((new TransportResult(false, null, 'dns'))->isRetryable());
        self::assertSame('dns', (new TransportResult(false, null, 'dns'))->getErrorMessage());
        self::assertTrue((new TransportResult(false, 429))->isRetryable());
        self::assertTrue((new TransportResult(false, 502))->isRetryable());
        self::assertFalse((new TransportResult(false, 400))->isRetryable());
    }

    public function testNullTransport(): void
    {
        $transport = new NullTransport();
        self::assertFalse($transport->send('x')->isAccepted());
        self::assertTrue($transport->flush(1.0));
    }
}
