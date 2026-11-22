<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Rendering;

use Nowo\ReportKitBundle\Application\Chart\ReportChartSvg;
use Nowo\ReportKitBundle\Contract\ReportSection;

use function array_fill;
use function array_keys;
use function array_map;
use function array_search;
use function array_slice;
use function array_sum;
use function count;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function mb_strimwidth;
use function number_format;
use function round;
use function str_ends_with;

/**
 * View model of one section for the PDF (and, from S2, the page): `kpis` (cards with sparklines), `series` (stacked
 * daily columns as SVG, light and dark, plus total / peak / mean), `slices` (horizontal bars with shares) or a table
 * (`table`, `details`, `trace`, `comments`, `tags`) cut to `limits.pdf_max_rows` rows and `pdf_max_cell` characters
 * per text cell (CSV / XLSX keep everything). Static and stateless.
 *
 * @internal
 */
final class PdfSectionView
{
    /**
     * @return array<string, mixed>
     */
    public static function build(ReportSection $section, string $accent, int $maxRows, int $maxCell): array
    {
        if ($section->keyValue) {
            return self::kpis($section, $accent);
        }
        if ($section->chart === ReportSection::CHART_SERIES) {
            return self::series($section, $accent);
        }
        if ($section->chart === ReportSection::CHART_SLICES) {
            return self::slices($section, $accent);
        }
        $rows = array_map(
            static fn (array $row): array => array_map(static fn (int|float|string|null $cell): int|float|string|null => is_string($cell) ? mb_strimwidth($cell, 0, $maxCell, '…', 'UTF-8') : $cell, $row),
            array_slice($section->rows, 0, $maxRows),
        );

        return [
            'kind'      => $section->style ?? 'table',
            'section'   => $section,
            'highlight' => $section->highlight,
            'rows'      => $rows,
            'cut'       => count($section->rows) > $maxRows ? $maxRows : $section->truncatedAt,
            // A "(%)" column is a signed change: +12 % / -5 %.
            'percent' => array_map(static fn (string $header): bool => str_ends_with($header, '(%)'), $section->headers),
            'numeric' => array_map(
                static function (int $column) use ($section): bool {
                    if ($section->rows === []) {
                        return false;
                    }
                    foreach ($section->rows as $row) {
                        $cell = $row[$column] ?? null;
                        if ($cell !== null && !is_int($cell) && !is_float($cell)) {
                            return false;
                        }
                    }

                    return true;
                },
                array_keys($section->headers),
            ),
        ];
    }

    /**
     * A figure for a card: `—` for null, thousands separators, one decimal for floats.
     */
    public static function display(int|float|string|null $value): string
    {
        return match (true) {
            $value === null  => '—',
            is_int($value)   => number_format($value),
            is_float($value) => number_format($value, 1),
            default          => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function kpis(ReportSection $section, string $accent): array
    {
        $sparks = $section->sparks === [] ? array_fill(0, count($section->rows), []) : $section->sparks;
        $cards  = [];
        foreach ($section->rows as $i => $row) {
            $svg     = ReportChartSvg::sparkline($sparks[$i] ?? [], $accent);
            $cards[] = [
                'label' => (string) $row[0],
                'value' => self::display($row[1] ?? null),
                'text'  => is_string($row[1] ?? null),
                'delta' => $row[2] ?? null,
                'tone'  => $section->tones[$i] ?? null,
                'spark' => $svg === null ? null : ReportChartSvg::dataUri($svg),
            ];
        }

        return ['kind' => 'kpis', 'section' => $section, 'cards' => $cards];
    }

    /**
     * @return array<string, mixed>
     */
    private static function series(ReportSection $section, string $accent): array
    {
        $colours   = ReportChartSvg::colours($accent, count($section->headers) - 1);
        $totals    = array_map(static fn (array $row): float => array_sum(array_map(self::number(...), array_slice($row, 1))), $section->rows);
        $peak      = $totals === [] ? 0.0 : max($totals);
        $peakIndex = $totals === [] ? false : array_search($peak, $totals, true);

        return [
            'kind'     => 'series',
            'section'  => $section,
            'svg'      => ReportChartSvg::dataUri(ReportChartSvg::stackedColumns($section->rows, $colours)),
            'svg_dark' => ReportChartSvg::dataUri(ReportChartSvg::stackedColumns($section->rows, $colours, true)),
            'legend'   => array_map(null, array_slice($section->headers, 1), $colours),
            'total'    => array_sum($totals),
            'peak'     => $peak,
            'peak_day' => $peakIndex === false ? null : $section->rows[$peakIndex][0],
            'mean'     => $totals === [] ? 0.0 : round(array_sum($totals) / count($totals), 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function slices(ReportSection $section, string $accent): array
    {
        $values  = array_map(static fn (array $row): float => self::number($row[1] ?? null), $section->rows);
        $sum     = array_sum($values);
        $max     = $values === [] ? 0.0 : max($values);
        $colours = ReportChartSvg::colours($accent, count(ReportChartSvg::PALETTE) + 1);
        $slices  = [];
        foreach ($section->rows as $i => $row) {
            $slices[] = [
                'label'  => (string) $row[0],
                'value'  => $values[$i],
                'share'  => $sum > 0 ? (int) round($values[$i] * 100 / $sum) : 0,
                'width'  => $max > 0 ? (int) round($values[$i] * 100 / $max) : 0,
                'colour' => $colours[$i % count($colours)],
            ];
        }

        return ['kind' => 'slices', 'section' => $section, 'slices' => $slices, 'total' => $sum];
    }

    private static function number(int|float|string|null $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }
}
