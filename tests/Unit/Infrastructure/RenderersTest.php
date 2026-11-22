<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use Nowo\ReportKitBundle\Application\Export\RenderBudget;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\RenderedFile;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Infrastructure\Csv\BuiltInCsvCellGuard;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use Nowo\ReportKitBundle\Infrastructure\Rendering\CsvReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\JsonReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\XlsxReportRenderer;
use Nowo\ReportKitBundle\Tests\Fixtures\Rendering;
use Nowo\ReportKitBundle\Tests\Fixtures\SampleReports;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function html_entity_decode;
use function json_decode;
use function json_encode;
use function preg_match_all;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use const ENT_QUOTES;
use const ENT_XML1;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PREG_SET_ORDER;

/**
 * Golden-file tests of the CSV bytes, the XLSX sheets and the JSON model (set UPDATE_GOLDEN=1 to rewrite them after a
 * deliberate change, then review the diff).
 */
final class RenderersTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../Fixtures/golden/';

    private string $tempBase;

    protected function setUp(): void
    {
        $this->tempBase = sys_get_temp_dir() . '/nowo-report-kit-renderers';
        (new Filesystem())->mkdir($this->tempBase);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempBase);
    }

    public function testCsvGoldenBytes(): void
    {
        $renderer = new CsvReportRenderer(new BuiltInCsvCellGuard(), Rendering::translator(), new RenderLimits());
        self::assertSame(ReportFormat::Csv, $renderer->format());

        $this->assertGolden('report.csv', $this->render($renderer, SampleReports::full()));
        $this->assertGolden('report.es.csv', $this->render($renderer, SampleReports::full('es')));
    }

    public function testCsvAppliesTheConfiguredRowLimit(): void
    {
        $csv = $this->render(new CsvReportRenderer(new BuiltInCsvCellGuard(), Rendering::translator(), new RenderLimits(maxRowsPerSection: 2)), SampleReports::long(5));

        self::assertStringContainsString("\"row 2\",2\n\"Only the first 2 rows are included.\"\n", $csv);
        self::assertStringNotContainsString('row 3', $csv);
    }

    public function testXlsxGoldenSheetsStringsNeverFormulas(): void
    {
        $renderer = new XlsxReportRenderer(Rendering::translator(), new RenderLimits(), new PrivateTempDirectory($this->tempBase));
        self::assertSame(ReportFormat::Xlsx, $renderer->format());
        $sheets = self::sheets($this->render($renderer, SampleReports::full()));

        $this->assertGolden('report.xlsx.json', json_encode($sheets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        self::assertSame(['.', '..'], scandir($this->tempBase), 'the private directory is removed');
    }

    public function testXlsxSheetNamesAreExcelSafe(): void
    {
        self::assertSame('a b c d', XlsxReportRenderer::sheetName('a[b]:c*?/\\d', []));
        self::assertSame('Sheet', XlsxReportRenderer::sheetName("''", []));
        self::assertSame('1234567890123456789012345678901', XlsxReportRenderer::sheetName('12345678901234567890123456789012345', []));
        self::assertSame('Orders (2)', XlsxReportRenderer::sheetName('Orders', ['ORDERS']));
        self::assertSame('Orders (3)', XlsxReportRenderer::sheetName('Orders', ['orders', 'Orders (2)']));
        self::assertSame('123456789012345678901234567 (2)', XlsxReportRenderer::sheetName('1234567890123456789012345678901', ['1234567890123456789012345678901']));
    }

    public function testXlsxRemovesItsDirectoryWhenTheBudgetRunsOut(): void
    {
        $clock  = new MockClock();
        $budget = new RenderBudget($clock, 1);
        $clock->sleep(2);
        $renderer = new XlsxReportRenderer(Rendering::translator(), new RenderLimits(maxRowsPerSection: 2), new PrivateTempDirectory($this->tempBase));

        try {
            $renderer->render(SampleReports::long(5), $budget, RenderedFile::temporaryStream());
            self::fail('no limit');
        } catch (ReportLimitExceededException) {
        }
        self::assertSame(['.', '..'], scandir($this->tempBase));

        $sheets = self::sheets($this->render($renderer, SampleReports::long(5)));
        self::assertSame([['Label', 'N'], ['row 1', 1], ['row 2', 2], ['Only the first 2 rows are included.']], $sheets['Rows']);
    }

    public function testJsonGolden(): void
    {
        $renderer = new JsonReportRenderer(new RenderLimits(maxRowsPerSection: 3));
        self::assertSame(ReportFormat::Json, $renderer->format());
        $json = $this->render($renderer, SampleReports::full());

        $this->assertGolden('report.json', json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    }

    private function render(CsvReportRenderer|XlsxReportRenderer|JsonReportRenderer $renderer, Report $report): string
    {
        $stream = RenderedFile::temporaryStream();
        $renderer->render($report, new RenderBudget(new MockClock(), 25), $stream);

        return (new RenderedFile('x.bin', 'x', $stream, 0))->contents();
    }

    /**
     * Sheet name => rows of cell values; a string cell is `"s:<text>"` unless plain, a formula would show as `f:`.
     *
     * @return array<string, list<list<float|int|string>>>
     */
    public static function sheets(string $bytes): array
    {
        $path = sys_get_temp_dir() . '/nowo-report-kit-sheet-test.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        preg_match_all('/<sheet name="([^"]*)"/', $workbook, $names);
        $sheets = [];
        foreach ($names[1] as $i => $name) {
            $xml = (string) $zip->getFromName('xl/worksheets/sheet' . ($i + 1) . '.xml');
            self::assertStringNotContainsString('<f>', $xml, 'no formula cell');
            $rows = [];
            preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $rowMatches);
            foreach ($rowMatches[1] as $rowXml) {
                $cells = [];
                preg_match_all('/<c ([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cellMatches, PREG_SET_ORDER);
                foreach ($cellMatches as $match) {
                    $inner = $match[2] ?? '';
                    preg_match('/\bt="(\w+)"/', $match[1], $type);
                    if (($type[1] ?? '') === 'inlineStr') {
                        preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $t);
                        $cells[] = html_entity_decode($t[1] ?? '', ENT_QUOTES | ENT_XML1);
                    } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $v) === 1) {
                        $cells[] = str_contains($v[1], '.') ? (float) $v[1] : (int) $v[1];
                    } else {
                        $cells[] = '';
                    }
                }
                $rows[] = $cells;
            }
            $sheets[html_entity_decode($name, ENT_QUOTES | ENT_XML1)] = $rows;
        }
        $zip->close();
        @unlink($path);

        return $sheets;
    }

    private function assertGolden(string $name, string $actual): void
    {
        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents(self::GOLDEN . $name, $actual);
        }
        self::assertSame((string) file_get_contents(self::GOLDEN . $name), $actual, 'golden file ' . $name);
    }
}
