<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Stringable;

use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function mb_check_encoding;
use function mb_scrub;
use function min;

/**
 * One block of a report (Beacon's class, same API): a key-value list (label, value, optional delta, tone and
 * sparkline) or a table (headers and rows of scalars) with an optional chart kind and presentation style.
 * `truncatedAt` notes a list cut to its first N rows.
 *
 * Cells are scalars only: `int`, finite `float`, UTF-8 `string` or `null` (booleans become 1 / 0, `Stringable` its
 * string, anything else null; invalid UTF-8 is scrubbed). A table keeps at most {@see self::MAX_ROWS} rows; the
 * exporter applies the configured `limits.max_rows_per_section` on top ({@see self::limitedTo()}).
 */
final readonly class ReportSection
{
    /** Rows are a time series (first column the day `Y-m-d`, then one column per series). */
    public const CHART_SERIES = 'series';
    /** Rows are labelled shares of one total (first column the label, then the value). */
    public const CHART_SLICES = 'slices';

    /** PDF / screen presentation of a table: label / value pairs (CSV / XLSX keep the plain rows). */
    public const STYLE_DETAILS = 'details';
    /** A stack trace: rows `[n, location, function]`, `$highlight` marks the app's own frames. */
    public const STYLE_TRACE = 'trace';
    /** Quoted text blocks: date, author, text. */
    public const STYLE_COMMENTS = 'comments';
    /** Label / value chips. */
    public const STYLE_TAGS = 'tags';

    /** Tones of a key value: the change is good, bad or neutral; `none` = no comparison shown. */
    public const TONES = ['good', 'bad', 'neutral', 'none'];

    /** Hard cap of rows per section (contract §4.1, the default of `limits.max_rows_per_section`). */
    public const MAX_ROWS = 50000;

    /** Most columns per table. */
    public const MAX_COLUMNS = 100;

    /**
     * @param list<string> $headers
     * @param list<list<float|int|string|null>> $rows
     * @param self::CHART_*|null $chart
     * @param list<?string> $tones key-value rows: one of {@see self::TONES} or null (a change without a tone)
     * @param list<list<int>> $sparks key-value rows: the trend behind each figure (sparkline)
     * @param self::STYLE_*|null $style presentation of a table in the PDF and on screen
     * @param list<bool> $highlight rows to set apart (a trace's own-code frames)
     */
    private function __construct(
        public string $title,
        public bool $keyValue,
        public array $headers,
        public array $rows,
        public ?int $truncatedAt = null,
        public ?string $chart = null,
        public array $tones = [],
        public array $sparks = [],
        public ?string $style = null,
        public array $highlight = [],
    ) {
    }

    /**
     * A key-value section: one card per item in the PDF and on screen; three columns (label, value, delta) in
     * CSV / XLSX.
     *
     * @param list<array{label: string, value: float|int|string|null, delta?: ?int, tone?: ?string, spark?: list<int>}> $items
     */
    public static function keyValues(string $title, string $labelHeader, string $valueHeader, string $deltaHeader, array $items): self
    {
        if (count($items) > self::MAX_ROWS) {
            throw new InvalidReportArgumentException('ReportSection keyValues accepts at most 50000 items.');
        }
        $rows   = [];
        $tones  = [];
        $sparks = [];
        foreach (array_values($items) as $item) {
            $tone = array_key_exists('delta', $item) ? ($item['tone'] ?? null) : 'none';
            if ($tone !== null && !in_array($tone, self::TONES, true)) {
                throw new InvalidReportArgumentException('ReportSection tone must be good, bad, neutral, none or null.');
            }
            $spark = [];
            foreach ($item['spark'] ?? [] as $point) {
                $spark[] = is_int($point) ? $point : 0;
            }
            $rows[]   = [self::text($item['label']), self::scalar($item['value']), self::scalar($item['delta'] ?? null)];
            $tones[]  = $tone;
            $sparks[] = $spark;
        }

        return new self(self::text($title), true, [self::text($labelHeader), self::text($valueHeader), self::text($deltaHeader)], $rows, tones: $tones, sparks: $sparks);
    }

    /**
     * A table section.
     *
     * @param list<string> $headers column titles (1 to 100)
     * @param array<array<mixed>> $rows cells kept when scalar, otherwise written as their string form or empty
     * @param int|null $truncatedAt the host already cut the list to its first N rows
     * @param self::CHART_*|null $chart how the PDF and the page draw the rows (CSV / XLSX keep the table)
     * @param self::STYLE_*|null $style presentation in the PDF and on screen (details, trace, tags, comments)
     * @param list<bool> $highlight rows to set apart (a trace's own-code frames)
     */
    public static function table(string $title, array $headers, array $rows, ?int $truncatedAt = null, ?string $chart = null, ?string $style = null, array $highlight = []): self
    {
        if ($headers === [] || count($headers) > self::MAX_COLUMNS || !array_is_list($headers)) {
            throw new InvalidReportArgumentException('ReportSection table needs 1 to 100 headers.');
        }
        if ($chart !== null && !in_array($chart, [self::CHART_SERIES, self::CHART_SLICES], true)) {
            throw new InvalidReportArgumentException('ReportSection chart must be series, slices or null.');
        }
        if ($style !== null && !in_array($style, [self::STYLE_DETAILS, self::STYLE_TRACE, self::STYLE_COMMENTS, self::STYLE_TAGS], true)) {
            throw new InvalidReportArgumentException('ReportSection style must be details, trace, comments, tags or null.');
        }
        if ($truncatedAt !== null && $truncatedAt < 0) {
            throw new InvalidReportArgumentException('ReportSection truncatedAt must not be negative.');
        }
        if (count($rows) > self::MAX_ROWS) {
            $rows        = array_slice($rows, 0, self::MAX_ROWS);
            $truncatedAt = min($truncatedAt ?? self::MAX_ROWS, self::MAX_ROWS);
        }
        $cells = [];
        foreach ($rows as $row) {
            $cells[] = array_values(array_map(self::scalar(...), array_slice($row, 0, self::MAX_COLUMNS)));
        }
        $flags = [];
        foreach (array_slice($highlight, 0, count($cells)) as $flag) {
            $flags[] = $flag === true;
        }

        return new self(self::text($title), false, array_map(self::text(...), $headers), $cells, $truncatedAt, $chart, style: $style, highlight: $flags);
    }

    /**
     * This section with at most $maxRows rows (the configured `limits.max_rows_per_section` or `pdf_max_rows`); the
     * truncation note then says $maxRows. Returns the same section when it is short enough.
     */
    public function limitedTo(int $maxRows): self
    {
        if ($maxRows < 1) {
            throw new InvalidReportArgumentException('ReportSection limit must be at least 1.');
        }
        if (count($this->rows) <= $maxRows) {
            return $this;
        }

        return new self(
            $this->title,
            $this->keyValue,
            $this->headers,
            array_slice($this->rows, 0, $maxRows),
            min($this->truncatedAt ?? $maxRows, $maxRows),
            $this->chart,
            array_slice($this->tones, 0, $maxRows),
            array_slice($this->sparks, 0, $maxRows),
            $this->style,
            array_slice($this->highlight, 0, $maxRows),
        );
    }

    private static function scalar(mixed $value): int|float|string|null
    {
        return match (true) {
            $value === null, is_int($value) => $value,
            is_float($value)                => is_finite($value) ? $value : null,
            is_string($value)               => self::text($value),
            is_bool($value)                 => $value ? 1 : 0,
            $value instanceof Stringable    => self::text((string) $value),
            default                         => null,
        };
    }

    private static function text(mixed $value): string
    {
        $text = is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }
}
