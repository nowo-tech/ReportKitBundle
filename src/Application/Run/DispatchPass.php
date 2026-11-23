<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Run;

use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;

/**
 * The result of one bounded claim pass: the runs it won and, when the pass stopped before the end, the rest.
 *
 * @internal
 */
final readonly class DispatchPass
{
    /**
     * @param list<RunScheduledReportMessage> $runs one per claimed slot
     * @param int $scanned due rows read
     */
    public function __construct(public array $runs, public ?DispatchDueReportsMessage $remainder, public int $scanned)
    {
    }
}
