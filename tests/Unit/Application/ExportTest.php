<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Application;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Application\ConfigReportSettingsProvider;
use Nowo\ReportKitBundle\Application\Export\RenderBudget;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Application\Export\ReportExporter;
use Nowo\ReportKitBundle\Application\Export\ReportFileName;
use Nowo\ReportKitBundle\Application\Export\ReportRendererInterface;
use Nowo\ReportKitBundle\Contract\Exception\FormatNotEnabledException;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

use function fwrite;
use function preg_match;
use function str_repeat;

final class ExportTest extends TestCase
{
    public function testTheExporterNamesTheFileAndKeepsTheBytes(): void
    {
        $file = $this->exporter(['csv'])->export($this->report('Orders · Café_Ñ / 2026'), ReportFormat::Csv);

        self::assertSame(1, preg_match('/^acme-report-orders-[a-z0-9-]+\.csv$/D', $file->filename));
        self::assertSame('text/csv; charset=UTF-8', $file->contentType);
        self::assertSame('csv-bytes', $file->contents());
        self::assertSame(9, $file->size);
    }

    public function testAFormatOffOrWithoutRendererIsRefused(): void
    {
        $exporter = $this->exporter(['csv', 'pdf']);
        self::assertSame([ReportFormat::Csv], $exporter->formats());
        foreach ([ReportFormat::Pdf, ReportFormat::Json] as $format) {
            try {
                $exporter->export($this->report('x'), $format);
                self::fail('exported');
            } catch (FormatNotEnabledException $e) {
                self::assertSame($format, $e->format);
            }
        }
        $this->expectException(FormatNotEnabledException::class);
        $this->exporter([])->export($this->report('x'), ReportFormat::Csv);
    }

    public function testARendererFailureClosesTheStreamAndRethrows(): void
    {
        $failing = new class implements ReportRendererInterface {
            public function format(): ReportFormat
            {
                return ReportFormat::Json;
            }

            public function render(Report $report, RenderBudget $budget, mixed $stream): void
            {
                throw new RuntimeException('boom');
            }
        };
        $exporter = new ReportExporter([$failing], new ConfigReportSettingsProvider(true, ['json'], false), new MockClock(), new RenderLimits());

        $this->expectExceptionMessage('boom');
        $exporter->export($this->report('x'), ReportFormat::Json);
    }

    public function testTheBudgetRunsOutAfterTheConfiguredSeconds(): void
    {
        $clock  = new MockClock('2026-11-22 10:00:00');
        $budget = new RenderBudget($clock, 25);
        $clock->sleep(25);
        $budget->check();
        $clock->sleep(1);

        try {
            $budget->check();
            self::fail('no limit');
        } catch (ReportLimitExceededException $e) {
            self::assertSame(ReportLimitExceededException::RENDER_SECONDS, $e->limit);
            self::assertSame(25, $e->allowed);
        }
    }

    public function testFileNamesAreSlugs(): void
    {
        self::assertSame('report-orders-all-2026-11-22.pdf', ReportFileName::build('report', 'orders-all-2026-11-22', ReportFormat::Pdf));
        self::assertSame('report.csv', ReportFileName::build('', '../..', ReportFormat::Csv));
        self::assertSame('x-a-b.json', ReportFileName::build('x', "A\"\r\n/B", ReportFormat::Json));
        self::assertSame(str_repeat('ab-', 39) . 'ab', ReportFileName::slug(str_repeat('ab-', 100)));
    }

    /**
     * @param list<string> $formats
     */
    private function exporter(array $formats): ReportExporter
    {
        $csv = new class implements ReportRendererInterface {
            public function format(): ReportFormat
            {
                return ReportFormat::Csv;
            }

            public function render(Report $report, RenderBudget $budget, mixed $stream): void
            {
                $budget->check();
                fwrite($stream, 'csv-bytes');
            }
        };

        return new ReportExporter([$csv], new ConfigReportSettingsProvider(true, $formats, false), new MockClock(), new RenderLimits(), 'acme-report');
    }

    private function report(string $filename): Report
    {
        return new Report('T', 'F', new DateTimeImmutable(), [], $filename);
    }
}
