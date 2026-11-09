<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Trace;

use Nowo\Beacon\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;

final class TraceIdProviderTest extends TestCase
{
    public function testGeneratesAndResets(): void
    {
        $provider = new TraceIdProvider();
        $first    = $provider->getOrCreate();
        self::assertSame($first, $provider->getOrCreate());
        $provider->reset();
        self::assertNull($provider->get());
        self::assertNotSame($first, $provider->getOrCreate());
    }

    public function testNormalizesHeaderValues(): void
    {
        self::assertNull(TraceIdProvider::normalize('bad id'));
        self::assertSame('abc_def-12', TraceIdProvider::normalize(' abc_def-12 '));
    }

    public function testSetAndFromMixed(): void
    {
        $provider = new TraceIdProvider();
        $provider->set('no');
        self::assertNull($provider->get());
        $provider->set('inbound-trace-1');
        self::assertSame('inbound-trace-1', $provider->getOrCreate());

        self::assertSame('inbound-trace-1', TraceIdProvider::fromMixed('inbound-trace-1'));
        self::assertNull(TraceIdProvider::fromMixed(42));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', TraceIdProvider::generate());
    }
}
