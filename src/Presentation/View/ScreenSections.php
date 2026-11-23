<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\View;

use Nowo\ReportKitBundle\Application\Chart\ReportChartSvg;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportSection;
use Nowo\ReportKitBundle\Infrastructure\Rendering\PdfSectionView;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Stat;
use Nowo\UiKitBundle\View\Table;

use function array_combine;
use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function is_float;
use function is_int;
use function is_string;
use function min;
use function sprintf;

/**
 * The sections of the report page (contract §8): the PDF view model ({@see PdfSectionView}, same bounds:
 * `limits.pdf_max_rows` rows, `limits.pdf_max_cell` characters) turned into UiKit views. Key figures become
 * {@see Stat} tiles, series an SVG image with a dark twin (generated from numbers and escaped labels only), slices
 * progress meters and every table style a UiKit {@see Table} (escaped cells, responsive cards). Static, stateless.
 *
 * @internal
 */
final class ScreenSections
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function build(Report $report, string $accent, int $maxRows, int $maxCell): array
    {
        $views = [];
        foreach ($report->sections as $i => $section) {
            $view    = PdfSectionView::build($section, $accent, $maxRows, $maxCell);
            $id      = sprintf('report-kit-section-%d', $i + 1);
            $views[] = match ($view['kind']) {
                'kpis'   => self::kpis($view, $id),
                'series' => self::series($section, $view, $id, $accent, $maxRows),
                'slices' => $view + ['id' => $id, 'empty' => $view['total'] <= 0],
                default  => self::table($section, $view, $id),
            };
        }

        return $views;
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return array<string, mixed>
     */
    private static function kpis(array $view, string $id): array
    {
        $stats = [];
        /** @var list<array{label: string, value: string, text: bool, delta: mixed, tone: string|null}> $cards */
        $cards = $view['cards'];
        foreach ($cards as $card) {
            $delta = $card['delta'];
            $tone  = $card['tone'];
            if ($tone === 'none') {
                $stats[] = new Stat($card['label'], $card['value']);
                continue;
            }
            $deltaTone = $tone === 'good' ? 'positive' : ($tone === 'bad' ? 'negative' : 'neutral');
            if (!is_int($delta) && !is_float($delta)) {
                // No previous value: "new" when the figure has a direction, else "no change data".
                $stats[] = new Stat($card['label'], $card['value'], delta: new Label($deltaTone === 'neutral' ? 'report.delta.none' : 'report.delta.new', NowoReportKitBundle::TRANSLATION_DOMAIN), deltaTone: $deltaTone);
                continue;
            }
            $stats[] = new Stat(
                $card['label'],
                $card['value'],
                delta: ($delta > 0 ? '+' : '') . $delta . ' %',
                trend: $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
                deltaTone: $deltaTone,
            );
        }

        return ['kind' => 'kpis', 'id' => $id, 'section' => $view['section'], 'stats' => $stats, 'cut' => $view['cut'], 'spark_cut' => $view['spark_cut'], 'empty' => $stats === []];
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return array<string, mixed>
     */
    private static function series(ReportSection $section, array $view, string $id, string $accent, int $maxRows): array
    {
        $rows    = array_slice($section->rows, 0, min(PdfSectionView::MAX_SERIES_ROWS, $maxRows));
        $colours = ReportChartSvg::colours($accent, count($section->headers) - 1);

        return $view + [
            'id'       => $id,
            'svg_dark' => ReportChartSvg::dataUri(ReportChartSvg::stackedColumns($rows, $colours, true)),
            'empty'    => $view['total'] <= 0,
        ];
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return array<string, mixed>
     */
    private static function table(ReportSection $section, array $view, string $id): array
    {
        $keys    = array_map(static fn (int $column): string => 'c' . $column, array_keys($section->headers));
        $columns = [];
        foreach ($section->headers as $column => $header) {
            $numeric   = $view['numeric'][$column] ?? false;
            $columns[] = new Column($keys[$column], $header, align: $numeric ? 'end' : 'start', required: $column === 0);
        }
        $rows = [];
        /** @var list<list<float|int|string|null>> $cells */
        $cells = $view['rows'];
        foreach ($cells as $row) {
            $values = [];
            foreach ($keys as $column => $key) {
                $values[] = self::cell($row[$column] ?? null, ($view['percent'][$column] ?? false) === true);
            }
            $rows[] = array_combine($keys, $values);
        }

        return [
            'kind'    => 'table',
            'style'   => $view['kind'],
            'id'      => $id,
            'section' => $section,
            'table'   => new Table($columns, $rows, caption: $section->title, captionVisible: false, attributes: ['data-testid' => 'report-kit-table']),
            'cut'     => $view['cut'],
            'empty'   => $rows === [],
        ];
    }

    private static function cell(int|float|string|null $value, bool $percent): string
    {
        if ($value === null) {
            return '—';
        }
        if ($percent && !is_string($value)) {
            return ($value > 0 ? '+' : '') . PdfSectionView::display($value) . ' %';
        }

        return PdfSectionView::display($value);
    }
}
