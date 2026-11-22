<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures;

use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportSection;

/**
 * Standard-host report type (extends the optional base): one row per context it may read, nothing else.
 */
final class OrdersReportType extends AbstractReportType
{
    public function key(): string
    {
        return 'orders';
    }

    public function build(ReportRequest $request): Report
    {
        $contexts = $request->parameters->context instanceof ContextRef ? [$request->parameters->context] : $request->accessible;
        $rows     = [];
        foreach ($contexts as $context) {
            $rows[] = [$context->key(), $request->owner->id, $request->parameters->periodDays];
        }

        return $this->report($request, [
            ReportSection::table($this->t($request, 'orders.section'), ['Context', 'Owner', 'Days'], $rows),
        ]);
    }
}
