<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Rendering;

use Nowo\ReportKitBundle\Application\Export\RenderBudget;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Application\Export\ReportRendererInterface;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Infrastructure\Csv\CsvCellGuardInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function fputcsv;
use function fwrite;

use const DATE_ATOM;

/**
 * One CSV for the whole report (Beacon's layout): the title, filters and generation time, then each section after a
 * blank line and its title row; UTF-8 with BOM; every text cell through the formula guard (`REQ-QA-019`); at most
 * `limits.max_rows_per_section` rows per section, then the truncation note.
 *
 * @internal
 */
final readonly class CsvReportRenderer implements ReportRendererInterface
{
    /** UTF-8 byte order mark: Excel then reads the file as UTF-8. */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * @param CsvCellGuardInterface $guard formula-injection guard
     * @param TranslatorInterface $translator the truncation note
     * @param RenderLimits $limits row cap
     */
    public function __construct(private CsvCellGuardInterface $guard, private TranslatorInterface $translator, private RenderLimits $limits)
    {
    }

    /**
     * CSV.
     */
    public function format(): ReportFormat
    {
        return ReportFormat::Csv;
    }

    /**
     * @param resource $stream
     */
    public function render(Report $report, RenderBudget $budget, mixed $stream): void
    {
        fwrite($stream, self::BOM);
        $this->write($stream, [$report->title]);
        $this->write($stream, [$report->filters]);
        $this->write($stream, [$report->generatedAt->format(DATE_ATOM)]);
        foreach ($report->sections as $section) {
            $budget->check();
            $section = $section->limitedTo($this->limits->maxRowsPerSection);
            $this->write($stream, []);
            $this->write($stream, [$section->title]);
            $this->write($stream, $section->headers);
            foreach ($section->rows as $row) {
                $this->write($stream, $row);
            }
            if ($section->truncatedAt !== null) {
                $this->write($stream, [$this->translator->trans('report.truncated', ['%count%' => $section->truncatedAt], 'NowoReportKitBundle', $report->locale)]);
            }
        }
    }

    /**
     * @param resource $stream
     * @param list<float|int|string|null> $row
     */
    private function write(mixed $stream, array $row): void
    {
        fputcsv($stream, $this->guard->row($row), ',', '"', '');
    }
}
