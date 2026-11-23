<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Run;

use Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage;

/**
 * The result of one bounded retention pass: rows deleted and, when the pass stopped early, the rest.
 *
 * @internal
 */
final readonly class PruneReport
{
    /**
     * Holds the counts.
     */
    public function __construct(public int $deleted, public ?PruneReportRunsMessage $remainder)
    {
    }
}
