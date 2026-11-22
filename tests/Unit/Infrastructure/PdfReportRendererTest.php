<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use LogicException;
use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetOrigin;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandAssetReadException;
use Nowo\Brand\Contract\BrandColors;
use Nowo\ReportKitBundle\Application\Brand\DefaultReportBrandResolver;
use Nowo\ReportKitBundle\Application\Export\RenderBudget;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\PdfDocumentRendererInterface;
use Nowo\ReportKitBundle\Contract\PdfOptions;
use Nowo\ReportKitBundle\Contract\RenderedFile;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportSection;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use Nowo\ReportKitBundle\Infrastructure\Pdf\DompdfDocumentRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\PdfReportRenderer;
use Nowo\ReportKitBundle\Tests\Fixtures\Rendering;
use Nowo\ReportKitBundle\Tests\Fixtures\SampleReports;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\Translator;

use function array_key_exists;
use function base64_encode;
use function hash;
use function is_array;
use function scandir;
use function sys_get_temp_dir;

/**
 * PDF golden test by text extraction: title, filters, sections, page numbers, brand; plus the logo rules.
 */
final class PdfReportRendererTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/nowo-report-kit-pdf-report';
        (new Filesystem())->mkdir($this->base);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->base);
    }

    public function testTextExtractionHoldsTheReport(): void
    {
        $renderer = $this->renderer(new DefaultReportBrandResolver());
        self::assertSame(ReportFormat::Pdf, $renderer->format());
        $text = (new Parser())->parseContent($this->render($renderer, SampleReports::full()))->getText();

        foreach (['Reports', 'Orders', 'Last 30 days · Acme', 'Generated 2026-11-22', 'Key figures', '1,234', 'Spain', 'vs previous period', 'Orders per day', 'Total', 'By status', 'paid', 'Customers', '=HYPERLINK', 'Only the first 4 rows are included.', 'Page 1 of 2', 'Page 2 of 2', 'Reports · Orders', 'Orders · Last 30 days · Acme', 'AT A GLANCE', 'Open in the app'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertSame(['.', '..'], scandir($this->base));
    }

    public function testTheReportLocaleIsUsedWithoutChangingTheTranslator(): void
    {
        $translator = Rendering::translator('en');
        $renderer   = $this->renderer(new DefaultReportBrandResolver(), null, $translator);
        $text       = (new Parser())->parseContent($this->render($renderer, SampleReports::full('de')))->getText();

        self::assertStringContainsString('Seite 2 von 2', $text);
        self::assertStringContainsString('AUF EINEN BLICK', $text);
        self::assertSame('en', $translator->getLocale());

        // No report locale: the translator's current locale.
        self::assertStringContainsString('Page 1 of 2', (new Parser())->parseContent($this->render($renderer, SampleReports::full(null)))->getText());
    }

    public function testTheBrandOfTheContextAndItsLogoAsADataUri(): void
    {
        $svg   = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#f00"/></svg>';
        $asset = new BrandAsset('mark', '/brand/mark.svg', 'image/svg+xml', null, null, hash('sha256', $svg), BrandAssetOrigin::ConfigDefault);
        $seen  = [];
        $spy   = new class($seen) implements PdfDocumentRendererInterface {
            /** @param array<string, mixed> $seen */
            public function __construct(public array $seen)
            {
            }

            public function logo(): mixed
            {
                $brand = $this->seen['brand'] ?? null;

                return is_array($brand) && array_key_exists('logo', $brand) ? $brand['logo'] : 'missing';
            }

            public function renderHtml(string $html, PdfOptions $options = new PdfOptions()): string
            {
                throw new LogicException('not used by the report renderer');
            }

            public function render(string $template, array $context, PdfOptions $options = new PdfOptions()): string
            {
                $this->seen = ['template' => $template, 'brand' => $context['brand'], 'options' => $options];

                return '%PDF';
            }
        };
        $brands = new class($asset) implements ReportBrandResolverInterface {
            public ?ContextRef $asked = null;

            public function __construct(private readonly BrandAsset $asset)
            {
            }

            public function brandFor(?ContextRef $context): Brand
            {
                $this->asked = $context;
                $colors      = new BrandColors('#AA0000', '#880000', '#c92a2a', '#e67700', '#ffffff', '#212529', '#f8f9fa');

                return new Brand('Acme', $colors, $colors, ['mark' => $this->asset]);
            }
        };
        $reader = new class($svg) implements BrandAssetReaderInterface {
            public function __construct(private readonly string $bytes)
            {
            }

            public function read(BrandAsset $asset, ?int $variant = null): string
            {
                return $this->bytes;
            }
        };

        $renderer = new PdfReportRenderer($spy, $brands, Rendering::translator(), new RenderLimits(), 'custom.html.twig', 'A3', $reader);
        self::assertSame('%PDF', $this->render($renderer, SampleReports::full()));
        self::assertEquals(new ContextRef('project', 'acme'), $brands->asked);
        self::assertSame('custom.html.twig', $spy->seen['template']);
        self::assertSame(['name' => 'Acme', 'accent' => '#aa0000', 'logo' => 'data:image/svg+xml;base64,' . base64_encode($svg)], $spy->seen['brand']);
        self::assertInstanceOf(PdfOptions::class, $spy->seen['options']);
        self::assertSame('A3', $spy->seen['options']->paper);
        self::assertSame('Acme · Orders', $spy->seen['options']->footerLeft);
        self::assertSame('Page {PAGE_NUM} of {PAGE_COUNT}', $spy->seen['options']->footerRight);
        self::assertSame('Orders · Last 30 days · Acme', $spy->seen['options']->runningHeader);

        // A reader that fails, or no reader: no logo, never a file or a URL.
        $failing = new class implements BrandAssetReaderInterface {
            public function read(BrandAsset $asset, ?int $variant = null): string
            {
                throw new BrandAssetReadException('gone');
            }
        };
        $this->render(new PdfReportRenderer($spy, $brands, Rendering::translator(), new RenderLimits(), 't', 'A4', $failing), SampleReports::full());
        self::assertNull($spy->logo());
        $this->render(new PdfReportRenderer($spy, $brands, Rendering::translator(), new RenderLimits()), SampleReports::full());
        self::assertNull($spy->logo());
        $this->render(new PdfReportRenderer($spy, new DefaultReportBrandResolver(), Rendering::translator(), new RenderLimits(), 't', 'A4', $reader), SampleReports::full());
        self::assertNull($spy->logo());
    }

    public function testAnEmptyReportListsItsEmptySections(): void
    {
        $report = new Report('Empty', 'F', new DateTimeImmutable('2026-11-22T10:00:00+00:00'), [
            ReportSection::table('Nothing here', ['a'], []),
            ReportSection::table('No series', ['d', 'v'], [['2026-11-22', 0]], chart: 'series'),
            ReportSection::table('No slices', ['l', 'v'], [], chart: 'slices'),
        ], 'empty', locale: 'en');
        $text = (new Parser())->parseContent($this->render($this->renderer(new DefaultReportBrandResolver()), $report))->getText();

        self::assertStringContainsString('No data for: Nothing here, No series, No slices', $text);
    }

    /**
     * Audit ZG-M1: 5,000 bars reach Dompdf as at most `pdf_max_rows`, with the truncation note.
     */
    public function testLongChartsAreCutWithTheNote(): void
    {
        $rows = [];
        for ($i = 0; $i < 5000; ++$i) {
            $rows[] = ['item-' . $i, $i + 1];
        }
        $report = new Report('Bars', 'F', new DateTimeImmutable('2026-11-22T10:00:00+00:00'), [ReportSection::table('Shares', ['l', 'v'], $rows, chart: 'slices')], 'bars', locale: 'en');
        $text   = (new Parser())->parseContent($this->render($this->renderer(new DefaultReportBrandResolver()), $report))->getText();

        self::assertStringContainsString('item-119', $text);
        self::assertStringNotContainsString('item-120', $text);
        self::assertStringContainsString('Only the first 120 rows are included.', $text);
    }

    private function renderer(ReportBrandResolverInterface $brands, ?BrandAssetReaderInterface $assets = null, ?Translator $translator = null): PdfReportRenderer
    {
        $translator ??= Rendering::translator();
        $pdf = new DompdfDocumentRenderer(Rendering::twig(), new PrivateTempDirectory($this->base), new RenderLimits(), $this->base . '/no-cache', new MockClock());

        return new PdfReportRenderer($pdf, $brands, $translator, new RenderLimits(), '@NowoReportKit/pdf/report.html.twig', 'A4', $assets);
    }

    private function render(PdfReportRenderer $renderer, Report $report): string
    {
        $stream = RenderedFile::temporaryStream();
        $renderer->render($report, new RenderBudget(new MockClock(), 25), $stream);

        return (new RenderedFile('x.pdf', 'x', $stream, 0))->contents();
    }
}
