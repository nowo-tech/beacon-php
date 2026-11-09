<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Fixtures;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2024-07-20T16:01:00.000000Z'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
