<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Chart;

use function array_map;
use function array_search;
use function array_slice;
use function array_sum;
use function base64_encode;
use function ceil;
use function count;
use function end;
use function explode;
use function floor;
use function htmlspecialchars;
use function implode;
use function is_float;
use function is_int;
use function log10;
use function max;
use function preg_match;
use function round;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const ENT_XML1;

/**
 * Server-side charts for the PDF and the page (Dompdf draws SVG, it runs no JavaScript): stacked daily columns with a
 * light grid, a value axis and sparse day labels; sparklines for key figures. Only rects, lines, polylines and text.
 * Built from numbers and escaped labels only; a colour that is not `#rrggbb` is replaced by grey. Light and dark
 * variants (grid and label ink). Static and stateless.
 */
final class ReportChartSvg
{
    /** Series colours after the accent (distinct in print and for common colour-vision deficiencies). */
    public const PALETTE = ['#e8590c', '#1c7ed6', '#2f9e44', '#ae3ec9', '#868e96', '#f08c00'];

    /** Grey used for an invalid colour. */
    public const FALLBACK = '#868e96';

    private const WIDTH  = 520;
    private const HEIGHT = 120;
    private const LEFT   = 34;
    private const BOTTOM = 18;
    private const TOP    = 14;

    /**
     * $count colours: the accent, then the palette.
     *
     * @return list<string>
     */
    public static function colours(string $accent, int $count): array
    {
        return array_slice([self::colour($accent), ...self::PALETTE], 0, max(0, $count));
    }

    /**
     * Stacked columns, one per row.
     *
     * @param list<list<float|int|string|null>> $rows first cell the day (`Y-m-d`) or a label, then one value per series
     * @param list<string> $colours one per series
     * @param bool $dark grid and labels for a dark page
     */
    public static function stackedColumns(array $rows, array $colours, bool $dark = false): string
    {
        $grid       = $dark ? '#3a4046' : '#dee2e6';
        $ink        = $dark ? '#adb5bd' : '#868e96';
        $totals     = array_map(static fn (array $row): float => array_sum(array_map(self::number(...), array_slice($row, 1))), $rows);
        $max        = self::niceMax($totals === [] ? 0.0 : max($totals));
        $plotWidth  = self::WIDTH - self::LEFT - 4;
        $plotHeight = self::HEIGHT - self::TOP - self::BOTTOM;
        $count      = max(1, count($rows));
        $slot       = $plotWidth / $count;
        $bar        = max(1.0, $slot * 0.72);

        $svg = [];
        foreach ([0, 0.5, 1] as $fraction) {
            $y     = self::TOP + $plotHeight * (1 - $fraction);
            $svg[] = sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="%s" stroke-width="0.6"/>', self::LEFT, $y, self::WIDTH - 4, $y, $grid);
            $svg[] = sprintf('<text x="%d" y="%.1f" font-size="7" fill="%s" text-anchor="end">%s</text>', self::LEFT - 4, $y + 2.5, $ink, self::short($max * $fraction));
        }
        $labelEvery = max(1, (int) ceil($count / 8));
        $peak       = $totals === [] ? -1 : (int) array_search(max($totals), $totals, true);
        foreach ($rows as $i => $row) {
            $x    = self::LEFT + $i * $slot + ($slot - $bar) / 2;
            $base = self::TOP + $plotHeight;
            foreach (array_slice($row, 1) as $s => $value) {
                $height = $max > 0 ? self::number($value) * $plotHeight / $max : 0.0;
                if ($height <= 0) {
                    continue;
                }
                $base -= $height;
                $svg[] = sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $x, $base, $bar, $height, self::colour($colours[$s] ?? self::FALLBACK));
            }
            if ($i === $peak && $totals[$i] > 0) {
                $svg[] = sprintf('<text x="%.1f" y="%.1f" font-size="7" font-weight="bold" fill="%s" text-anchor="middle">%s</text>', $x + $bar / 2, $base - 3, $ink, self::short($totals[$i]));
            }
            if ($i % $labelEvery === 0) {
                $svg[] = sprintf('<text x="%.1f" y="%d" font-size="7" fill="%s" text-anchor="middle">%s</text>', $x + $bar / 2, self::HEIGHT - 5, $ink, self::escape(self::day((string) ($row[0] ?? ''))));
            }
        }

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %1$d %2$d" font-family="DejaVu Sans, Arial, sans-serif">%s</svg>', self::WIDTH, self::HEIGHT, implode('', $svg));
    }

    /**
     * A small trend line: the series scaled to its own range, the last point marked. Null below two points or when
     * every point is zero or less.
     *
     * @param list<int> $values
     */
    public static function sparkline(array $values, string $colour): ?string
    {
        if (count($values) < 2 || max($values) <= 0) {
            return null;
        }
        $colour = self::colour($colour);
        $width  = 120;
        $height = 24;
        $max    = max($values);
        $step   = $width / (count($values) - 1);
        $points = [];
        foreach ($values as $i => $value) {
            $points[] = sprintf('%.1f,%.1f', $i * $step, $height - 2 - (max(0, $value) / $max) * ($height - 4));
        }
        [$lastX, $lastY] = explode(',', end($points));

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %1$d %2$d"><polyline points="%s" fill="none" stroke="%s" stroke-width="1.4" stroke-linejoin="round"/><circle cx="%s" cy="%s" r="1.8" fill="%4$s"/></svg>',
            $width,
            $height,
            implode(' ', $points),
            $colour,
            $lastX,
            $lastY,
        );
    }

    /**
     * `data:` URI for an `<img>` (Dompdf loads it without remote or file access).
     */
    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * $colour when it is `#rrggbb`, else {@see self::FALLBACK}.
     */
    public static function colour(string $colour): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $colour) === 1 ? $colour : self::FALLBACK;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function number(int|float|string|null $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /** Axis top: 1, 2 or 5 × 10ⁿ above the largest value. */
    private static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 0.0;
        }
        $magnitude = 10 ** floor(log10($max));
        foreach ([1, 2, 5] as $step) {
            if ($step * $magnitude >= $max) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    private static function short(float $value): string
    {
        return match (true) {
            $value >= 1000000 => round($value / 1000000, 1) . 'M',
            $value >= 1000    => round($value / 1000, 1) . 'k',
            default           => (string) round($value, 1),
        };
    }

    /** `2026-10-23` → `23/10`; other labels as they are. */
    private static function day(string $label): string
    {
        return preg_match('/^\d{4}-(\d{2})-(\d{2})$/D', $label, $m) === 1 ? $m[2] . '/' . $m[1] : $label;
    }
}
