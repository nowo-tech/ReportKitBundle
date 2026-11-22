<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Rendering;

use Nowo\ReportKitBundle\Application\Export\RenderBudget;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Application\Export\ReportRendererInterface;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function fclose;
use function fopen;
use function in_array;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function method_exists;
use function preg_replace;
use function stream_copy_to_stream;
use function trim;

/**
 * XLSX workbook (OpenSpout, streaming): an "About" sheet (title, filters, generation time), then one sheet per section
 * with a bold header row. Numbers stay numbers; **every string is a string cell, never a formula** (OpenSpout turns
 * a string starting with `=` into a formula when given a plain value; this renderer never does). Sheet names
 * sanitised (`[]:*?/\`, 31 characters, unique case-insensitively). OpenSpout works in a private per-call directory
 * removed in `finally`. Works with OpenSpout 4.28+ and 5.x.
 *
 * @internal
 */
final readonly class XlsxReportRenderer implements ReportRendererInterface
{
    /** Excel's limit for a sheet name. */
    public const SHEET_NAME_MAX = 31;

    /**
     * @param TranslatorInterface $translator sheet name of the "About" sheet, truncation note
     * @param RenderLimits $limits row cap
     * @param PrivateTempDirectory $temp per-call directory
     */
    public function __construct(private TranslatorInterface $translator, private RenderLimits $limits, private PrivateTempDirectory $temp)
    {
    }

    /**
     * XLSX.
     */
    public function format(): ReportFormat
    {
        return ReportFormat::Xlsx;
    }

    /**
     * @param resource $stream
     */
    public function render(Report $report, RenderBudget $budget, mixed $stream): void
    {
        $dir = $this->temp->create();
        try {
            $path   = $dir . '/report.xlsx';
            $writer = new Writer($this->options($dir));
            $writer->openToFile($path);
            try {
                $bold = $this->bold();
                $writer->getCurrentSheet()->setName(self::sheetName($this->trans($report, 'report.sheet.about'), []));
                $writer->addRow($this->row([$report->title], $bold));
                $writer->addRow($this->row([$report->filters]));
                $writer->addRow($this->row([$report->generatedAt->format('Y-m-d H:i P')]));

                $used = [];
                foreach ($report->sections as $section) {
                    $budget->check();
                    $section = $section->limitedTo($this->limits->maxRowsPerSection);
                    $name    = self::sheetName($section->title, $used);
                    $used[]  = $name;
                    $writer->addNewSheetAndMakeItCurrent()->setName($name);
                    $writer->addRow($this->row($section->headers, $bold));
                    foreach ($section->rows as $row) {
                        $writer->addRow($this->row($row));
                    }
                    if ($section->truncatedAt !== null) {
                        $writer->addRow($this->row([$this->trans($report, 'report.truncated', ['%count%' => $section->truncatedAt])]));
                    }
                }
            } finally {
                $writer->close();
            }
            $file = fopen($path, 'r');
            if ($file === false) {
                throw new RuntimeException('Cannot read the rendered workbook.'); // @codeCoverageIgnore
            }
            stream_copy_to_stream($file, $stream);
            fclose($file);
        } finally {
            $this->temp->discard($dir);
        }
    }

    /**
     * Excel refuses `[]:*?/\` in sheet names, more than 31 characters and duplicates (case-insensitive).
     *
     * @param list<string> $used names already taken
     */
    public static function sheetName(string $title, array $used): string
    {
        $base  = mb_substr(trim((string) preg_replace('/(?:[\[\]:*?\/\\\\]|\s|\p{Cc})+/u', ' ', $title)), 0, self::SHEET_NAME_MAX);
        $base  = trim($base, " '");
        $base  = $base === '' ? 'Sheet' : $base;
        $taken = array_map(static fn (string $name): string => mb_strtolower($name), $used);
        $name  = $base;
        for ($i = 2; in_array(mb_strtolower($name), $taken, true); ++$i) {
            $suffix = ' (' . $i . ')';
            $name   = mb_substr($base, 0, self::SHEET_NAME_MAX - mb_strlen($suffix)) . $suffix;
        }

        return $name;
    }

    /**
     * @param list<float|int|string|null> $values
     */
    private function row(array $values, ?Style $style = null): Row
    {
        $cells = [];
        foreach ($values as $value) {
            // A string is always a string cell: Cell::fromValue('=…') would build a formula.
            $cells[] = is_string($value) ? new StringCell($value, $style) : Cell::fromValue($value, $style);
        }

        return new Row($cells);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(Report $report, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'NowoReportKitBundle', $report->locale);
    }

    private function options(string $dir): Options
    {
        // The analysed install decides which branch PHPStan considers dead: 4.x on PHP 8.2/8.3, 5.x on 8.4+.
        if (method_exists(Options::class, 'setTempFolder')) { // @phpstan-ignore function.alreadyNarrowedType, function.impossibleType
            // OpenSpout 4.x
            $options = new Options();
            $options->setTempFolder($dir); // @phpstan-ignore method.notFound

            return $options;
        }

        // OpenSpout 5.x (PHP 8.4+ CI cells; the coverage cell runs 4.x)
        // @phpstan-ignore-next-line argument.unknown
        return new Options(tempFolder: $dir); // @codeCoverageIgnore
    }

    private function bold(): Style
    {
        $style = new Style();
        if (method_exists($style, 'setFontBold')) { // @phpstan-ignore function.alreadyNarrowedType, function.impossibleType
            // OpenSpout 4.x
            return $style->setFontBold(); // @phpstan-ignore method.notFound
        }

        // OpenSpout 5.x (PHP 8.4+ CI cells; the coverage cell runs 4.x)
        // @phpstan-ignore-next-line method.notFound
        return $style->withFontBold(true); // @codeCoverageIgnore
    }
}
