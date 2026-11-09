<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Breadcrumb;

use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer;
use PHPUnit\Framework\TestCase;

final class BreadcrumbBufferTest extends TestCase
{
    public function testAddsAndClears(): void
    {
        $buffer = new BreadcrumbBuffer();
        $buffer->add('clicked pay', 'ui', 'info', ['btn' => 'pay']);

        self::assertCount(1, $buffer->all());
        self::assertSame('clicked pay', $buffer->all()[0]['message']);

        $buffer->clear();
        self::assertSame([], $buffer->all());
    }

    public function testCapsAtFifty(): void
    {
        $buffer = new BreadcrumbBuffer();
        for ($i = 0; $i < 60; ++$i) {
            $buffer->add('m' . $i);
        }

        self::assertCount(50, $buffer->all());
        self::assertSame('m10', $buffer->all()[0]['message']);
        self::assertSame('m59', $buffer->all()[49]['message']);
    }

    public function testResetClears(): void
    {
        $buffer = new BreadcrumbBuffer();
        $buffer->add('x');
        $buffer->reset();
        self::assertSame([], $buffer->all());
    }
}
