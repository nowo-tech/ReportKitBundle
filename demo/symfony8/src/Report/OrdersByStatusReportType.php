<?php

declare(strict_types=1);

namespace App\Report;

use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\ParameterDefinition;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\ParameterType;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportSection;

/**
 * "Orders by status": a permission (`orders.view`), one project required, a "channel" extra; shares per status and
 * the latest orders (customer notes are free text: one starts with "=" to show the CSV formula guard).
 */
final class OrdersByStatusReportType extends AbstractReportType
{
    public function key(): string
    {
        return 'orders_by_status';
    }

    public function icon(): string
    {
        return 'cart';
    }

    public function permission(): string
    {
        return 'orders.view';
    }

    public function parameters(): ParameterSchema
    {
        return new ParameterSchema([7, 30], 30, true, true, [
            'channel' => new ParameterDefinition(ParameterType::Choice, 'demo.orders.channel', 'all', ['all', 'web', 'store']),
        ]);
    }

    public function build(ReportRequest $request): Report
    {
        $project = $request->parameters->context instanceof ContextRef ? $request->parameters->context->id : 'none';
        $channel = (string) $request->parameters->extra['channel'];
        $key     = $project . $channel . $request->parameters->periodDays;
        $shares  = [];
        foreach (DemoData::STATUSES as $status) {
            $shares[] = [$this->t($request, 'demo.orders.status.' . $status), 5 + DemoData::figure($key . $status, 60)];
        }
        $orders = [];
        for ($i = 1; $i <= 8; ++$i) {
            $status   = DemoData::STATUSES[DemoData::figure($key . $i, 4)];
            $orders[] = ['#' . (1000 + $i), $this->t($request, 'demo.orders.status.' . $status), 10 + DemoData::figure($key . 'amount' . $i, 190), $i === 3 ? '=SUM(A1:A9) please' : 'Gift wrap'];
        }

        return $this->report($request, [
            ReportSection::table($this->t($request, 'demo.orders.by_status'), ['Status', 'Orders'], $shares, chart: ReportSection::CHART_SLICES),
            ReportSection::table($this->t($request, 'demo.orders.latest'), ['Order', 'Status', 'Amount', 'Note'], $orders),
        ]);
    }
}
