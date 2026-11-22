<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Rendering;

use Nowo\ReportKitBundle\Application\Export\RenderBudget;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Application\Export\ReportRendererInterface;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportFormat;

use function fwrite;
use function json_encode;

use const DATE_ATOM;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The report model as JSON (for the optional API, S6): title, filters, generation time, the optional header fields
 * and every section with its rows (at most `limits.max_rows_per_section`). Never carries the owner or the accessible
 * contexts.
 *
 * @internal
 */
final readonly class JsonReportRenderer implements ReportRendererInterface
{
    /**
     * @param RenderLimits $limits row cap
     */
    public function __construct(private RenderLimits $limits)
    {
    }

    /**
     * JSON.
     */
    public function format(): ReportFormat
    {
        return ReportFormat::Json;
    }

    /**
     * @param resource $stream
     */
    public function render(Report $report, RenderBudget $budget, mixed $stream): void
    {
        $sections = [];
        foreach ($report->sections as $section) {
            $budget->check();
            $section    = $section->limitedTo($this->limits->maxRowsPerSection);
            $sections[] = [
                'title'       => $section->title,
                'kind'        => $section->keyValue ? 'key_values' : 'table',
                'chart'       => $section->chart,
                'style'       => $section->style,
                'headers'     => $section->headers,
                'rows'        => $section->rows,
                'tones'       => $section->tones,
                'sparks'      => $section->sparks,
                'highlight'   => $section->highlight,
                'truncatedAt' => $section->truncatedAt,
            ];
        }
        fwrite($stream, json_encode([
            'title'       => $report->title,
            'filters'     => $report->filters,
            'generatedAt' => $report->generatedAt->format(DATE_ATOM),
            'summary'     => $report->summary,
            'glance'      => $report->glance,
            'badges'      => $report->badges,
            'link'        => $report->link,
            'locale'      => $report->locale,
            'sections'    => $sections,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
