<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Rendering;

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
use function min;
use function number_format;
use function round;
use function str_ends_with;

/**
 * View model of one section for the PDF (and, from S2, the page): `kpis` (cards with sparklines), `series` (stacked
 * daily columns as SVG plus total / peak / mean), `slices` (horizontal bars with shares) or a table (`table`,
 * `details`, `trace`, `comments`, `tags`). Every kind is bounded (audit ZG-M1): cards, bars and table rows to
 * `limits.pdf_max_rows`, series columns to min({@see self::MAX_SERIES_ROWS}, `pdf_max_rows`), sparkline points to
 * {@see self::MAX_SPARK_POINTS} (the latest ones), text to `pdf_max_cell` characters; `cut` (and `spark_cut`) carry the
 * truncation note. CSV / XLSX keep everything. Static and stateless.
 *
 * @internal
 */
final class PdfSectionView
{
    /** Most columns of a series chart (one year of days). */
    public const MAX_SERIES_ROWS = 366;

    /** Most points of a sparkline (the latest ones are kept). */
    public const MAX_SPARK_POINTS = 366;

    /**
     * @return array<string, mixed>
     */
    public static function build(ReportSection $section, string $accent, int $maxRows, int $maxCell): array
    {
        if ($section->keyValue) {
            return self::kpis($section, $accent, $maxRows, $maxCell);
        }
        if ($section->chart === ReportSection::CHART_SERIES) {
            return self::series($section, $accent, min(self::MAX_SERIES_ROWS, $maxRows));
        }
        if ($section->chart === ReportSection::CHART_SLICES) {
            return self::slices($section, $accent, $maxRows, $maxCell);
        }
        $rows = array_map(
            static fn (array $row): array => array_map(static fn (int|float|string|null $cell): int|float|string|null => self::cell($cell, $maxCell), $row),
            array_slice($section->rows, 0, $maxRows),
        );

        return [
            'kind'      => $section->style ?? 'table',
            'section'   => $section,
            'highlight' => $section->highlight,
            'rows'      => $rows,
            'cut'       => self::cut($section, $maxRows),
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
     * The truncation count of a section shown with at most $maxRows rows: $maxRows when it is cut here, else the host's
     * own `truncatedAt`.
     */
    private static function cut(ReportSection $section, int $maxRows): ?int
    {
        return count($section->rows) > $maxRows ? $maxRows : $section->truncatedAt;
    }

    private static function cell(int|float|string|null $cell, int $maxCell): int|float|string|null
    {
        return is_string($cell) ? mb_strimwidth($cell, 0, $maxCell, '…', 'UTF-8') : $cell;
    }

    /**
     * @return array<string, mixed>
     */
    private static function kpis(ReportSection $section, string $accent, int $maxRows, int $maxCell): array
    {
        $sparks   = $section->sparks === [] ? array_fill(0, count($section->rows), []) : $section->sparks;
        $cards    = [];
        $sparkCut = null;
        foreach (array_slice($section->rows, 0, $maxRows) as $i => $row) {
            $points = $sparks[$i] ?? [];
            if (count($points) > self::MAX_SPARK_POINTS) {
                $points   = array_slice($points, -self::MAX_SPARK_POINTS);
                $sparkCut = self::MAX_SPARK_POINTS;
            }
            $svg     = ReportChartSvg::sparkline($points, $accent);
            $value   = $row[1] ?? null;
            $cards[] = [
                'label' => (string) self::cell($row[0], $maxCell),
                'value' => self::display(self::cell($value, $maxCell)),
                'text'  => is_string($value),
                'delta' => $row[2] ?? null,
                'tone'  => $section->tones[$i] ?? null,
                'spark' => $svg === null ? null : ReportChartSvg::dataUri($svg),
            ];
        }

        return ['kind' => 'kpis', 'section' => $section, 'cards' => $cards, 'cut' => self::cut($section, $maxRows), 'spark_cut' => $sparkCut];
    }

    /**
     * @return array<string, mixed>
     */
    private static function series(ReportSection $section, string $accent, int $maxRows): array
    {
        $rows      = array_slice($section->rows, 0, $maxRows);
        $colours   = ReportChartSvg::colours($accent, count($section->headers) - 1);
        $totals    = array_map(static fn (array $row): float => array_sum(array_map(self::number(...), array_slice($row, 1))), $rows);
        $peak      = $totals === [] ? 0.0 : max($totals);
        $peakIndex = $totals === [] ? false : array_search($peak, $totals, true);

        return [
            'kind'     => 'series',
            'section'  => $section,
            'svg'      => ReportChartSvg::dataUri(ReportChartSvg::stackedColumns($rows, $colours)),
            'legend'   => array_map(null, array_slice($section->headers, 1), $colours),
            'total'    => array_sum($totals),
            'peak'     => $peak,
            'peak_day' => $peakIndex === false ? null : $rows[$peakIndex][0],
            'mean'     => $totals === [] ? 0.0 : round(array_sum($totals) / count($totals), 1),
            'cut'      => self::cut($section, $maxRows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function slices(ReportSection $section, string $accent, int $maxRows, int $maxCell): array
    {
        $rows    = array_slice($section->rows, 0, $maxRows);
        $values  = array_map(static fn (array $row): float => self::number($row[1] ?? null), $rows);
        $sum     = array_sum($values);
        $max     = $values === [] ? 0.0 : max($values);
        $colours = ReportChartSvg::colours($accent, count(ReportChartSvg::PALETTE) + 1);
        $slices  = [];
        foreach ($rows as $i => $row) {
            $slices[] = [
                'label'  => (string) self::cell($row[0], $maxCell),
                'value'  => $values[$i],
                'share'  => $sum > 0 ? (int) round($values[$i] * 100 / $sum) : 0,
                'width'  => $max > 0 ? (int) round($values[$i] * 100 / $max) : 0,
                'colour' => $colours[$i % count($colours)],
            ];
        }

        return ['kind' => 'slices', 'section' => $section, 'slices' => $slices, 'total' => $sum, 'cut' => self::cut($section, $maxRows)];
    }

    private static function number(int|float|string|null $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }
}
