<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeImmutable;

/**
 * The UTC day window of a report (Beacon's `AbstractReportType::window()`): `[from, to)`, the start of the previous
 * window of the same length, today (UTC midnight) and the day keys `Y-m-d` of the window, oldest first.
 */
final readonly class ReportWindow
{
    /**
     * @param list<string> $days day keys `Y-m-d`, oldest first
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public DateTimeImmutable $previous,
        public DateTimeImmutable $today,
        public array $days,
    ) {
    }
}
