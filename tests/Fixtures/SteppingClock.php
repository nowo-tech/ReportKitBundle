<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock that moves $step seconds forward every time it is read: a render that checks its deadline often enough is
 * stopped after a known number of checks.
 */
final class SteppingClock implements ClockInterface
{
    private int $reads = 0;

    public function __construct(private readonly int $step, private readonly DateTimeImmutable $start = new DateTimeImmutable('2026-11-22T10:00:00+00:00'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->start->modify('+' . ($this->step * $this->reads++) . ' seconds');
    }

    public function reads(): int
    {
        return $this->reads;
    }
}
