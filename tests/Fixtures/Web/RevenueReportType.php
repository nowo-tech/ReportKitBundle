<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Web;

use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\ParameterDefinition;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\ParameterType;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportSection;

/**
 * A type with a permission (`revenue.view`) and one context required, plus every kind of extra: figures, a daily
 * series, shares and a table that lists exactly the contexts the request carries (the access tests read it) and a
 * formula-looking cell (the CSV guard test).
 */
final class RevenueReportType extends AbstractReportType
{
    public function key(): string
    {
        return 'revenue';
    }

    public function permission(): string
    {
        return 'revenue.view';
    }

    public function parameters(): ParameterSchema
    {
        return new ParameterSchema([7, 30], 7, true, true, [
            'channel' => new ParameterDefinition(ParameterType::Choice, 'revenue.channel', 'web', ['web', 'shop']),
            'limit'   => new ParameterDefinition(ParameterType::Int, 'revenue.limit', 10, min: 1, max: 100),
            'gross'   => new ParameterDefinition(ParameterType::Bool, 'revenue.gross', true),
            'since'   => new ParameterDefinition(ParameterType::Date, 'revenue.since'),
        ]);
    }

    public function build(ReportRequest $request): Report
    {
        $contexts = [];
        foreach ($request->accessible as $context) {
            $contexts[] = [$context->key(), $request->parameters->context instanceof ContextRef && $context->equals($request->parameters->context) ? 'effective' : 'accessible'];
        }
        $days = [];
        foreach ($request->window()->days as $i => $day) {
            $days[] = [$day, $i, 1];
        }

        return $this->report($request, [
            ReportSection::keyValues('Figures', 'Figure', 'Value', 'Change', [
                ['label' => 'Orders', 'value' => 42, 'delta' => 12, 'tone' => 'good'],
                ['label' => 'Refunds', 'value' => 3, 'delta' => -50, 'tone' => 'bad'],
                ['label' => 'Average', 'value' => 9.5, 'delta' => 0, 'tone' => 'neutral'],
                ['label' => 'Fresh', 'value' => 1, 'delta' => null, 'tone' => 'good'],
                ['label' => 'Flat', 'value' => 1, 'delta' => null, 'tone' => 'neutral'],
                ['label' => 'Plain', 'value' => 'text'],
            ]),
            ReportSection::table('Daily', ['Day', 'Web', 'Shop'], $days, chart: ReportSection::CHART_SERIES),
            ReportSection::table('Channels', ['Channel', 'Orders'], [['Web', 30], ['Shop', 12]], chart: ReportSection::CHART_SLICES),
            ReportSection::table('Contexts', ['Context', 'Role'], $contexts),
            ReportSection::table('Notes', ['Note', 'Change (%)', 'Missing'], [['=HYPERLINK("http://evil")', 5, null], ['<script>alert(1)</script>', -2.5, null]], style: ReportSection::STYLE_TRACE, highlight: [true, false]),
            ReportSection::table('Empty', ['Nothing'], []),
            ReportSection::table('Empty series', ['Day', 'Value'], [['2026-11-01', 0]], chart: ReportSection::CHART_SERIES),
        ], $request->parameters->extra['channel'] . '-' . $request->parameters->extra['limit']);
    }
}
