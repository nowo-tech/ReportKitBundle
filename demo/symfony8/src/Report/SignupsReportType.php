<?php

declare(strict_types=1);

namespace App\Report;

use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportSection;

use function array_slice;
use function array_sum;
use function round;

/**
 * "Signups per day": key figures with the change against the previous period and a daily series per project. Only
 * the request's contexts are read (the effective one, or every accessible one).
 */
final class SignupsReportType extends AbstractReportType
{
    public function key(): string
    {
        return 'signups';
    }

    public function icon(): string
    {
        return 'person-plus';
    }

    public function parameters(): ParameterSchema
    {
        return new ParameterSchema([7, 30, 90], 30);
    }

    public function build(ReportRequest $request): Report
    {
        $projects = $request->parameters->context instanceof ContextRef ? [$request->parameters->context] : $request->accessible;
        $window   = $request->window();
        $rows     = [];
        $current  = 0;
        foreach ($window->days as $day) {
            $row = [$day];
            foreach ($projects as $project) {
                $row[] = DemoData::figure($project->id . $day, 40);
            }
            $current += array_sum(array_slice($row, 1));
            $rows[] = $row;
        }
        $previous = 0;
        foreach ($projects as $project) {
            $previous += DemoData::figure($project->id . 'previous' . $request->parameters->periodDays, 40) * $request->parameters->periodDays;
        }
        $headers = ['Day'];
        foreach ($projects as $project) {
            $headers[] = DemoData::PROJECTS[$project->id] ?? $project->id;
        }
        $delta = $previous > 0 ? (int) round(($current - $previous) * 100 / $previous) : null;

        return $this->report($request, [
            ReportSection::keyValues($this->t($request, 'demo.signups.figures'), 'Figure', 'Value', 'Change', [
                ['label' => $this->t($request, 'demo.signups.total'), 'value' => $current, 'delta' => $delta, 'tone' => $delta !== null && $delta < 0 ? 'bad' : 'good'],
                ['label' => $this->t($request, 'demo.signups.daily'), 'value' => round($current / $request->parameters->periodDays, 1), 'delta' => null, 'tone' => 'none'],
            ]),
            ReportSection::table($this->t($request, 'demo.signups.per_day'), $headers, $rows, chart: ReportSection::CHART_SERIES),
        ]);
    }
}
