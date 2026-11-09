<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Span;

use Nowo\Beacon\Span\SpanBuffer;
use PHPUnit\Framework\TestCase;

final class SpanBufferTest extends TestCase
{
    public function testAddDrainAndBound(): void
    {
        $buffer = new SpanBuffer();
        $buffer->add('db', 'SELECT 1', 1.0, 2.0, ['rows' => 1]);
        $buffer->addTimed('http', 'GET /', 0.5, [], 10.0);
        $buffer->addTimed('cache', 'get', 0.1);

        $spans = $buffer->all();
        self::assertCount(3, $spans);
        self::assertSame(['rows' => 1], $spans[0]['data'] ?? null);
        self::assertArrayNotHasKey('data', $spans[1]);
        self::assertSame(9.5, $spans[1]['start_timestamp']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spans[0]['span_id']);

        self::assertCount(3, $buffer->drain());
        self::assertSame([], $buffer->all());

        for ($i = 0; $i < SpanBuffer::MAX_ITEMS + 5; ++$i) {
            $buffer->add('op', 'span ' . $i, 0.0, 1.0);
        }
        self::assertCount(SpanBuffer::MAX_ITEMS, $buffer->all());
        self::assertSame('span 5', $buffer->all()[0]['description']);

        $buffer->clear();
        self::assertSame([], $buffer->all());
        $buffer->add('op', 'x', 0.0, 1.0);
        $buffer->reset();
        self::assertSame([], $buffer->all());
    }
}
