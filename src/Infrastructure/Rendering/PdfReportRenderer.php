<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Rendering;

use InvalidArgumentException;
use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetDataUri;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandAssetReadException;
use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\ReportKitBundle\Application\Chart\ReportChartSvg;
use Nowo\ReportKitBundle\Application\Export\RenderBudget;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Application\Export\ReportRendererInterface;
use Nowo\ReportKitBundle\Application\Rendering\PdfSectionView;
use Nowo\ReportKitBundle\Contract\PdfDocumentRendererInterface;
use Nowo\ReportKitBundle\Contract\PdfOptions;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Symfony\Contracts\Translation\TranslatorInterface;

use function fwrite;
use function mb_strimwidth;

/**
 * The report as a PDF (contract §8): brand header (name, accent colour, logo as a data URI), the header fields, every
 * section through {@see PdfSectionView}, a footer with brand · title and *page x of y*, and a running header from
 * page 2. Rendered by the closed {@see PdfDocumentRendererInterface}.
 *
 * @internal
 */
final readonly class PdfReportRenderer implements ReportRendererInterface
{
    /**
     * @param PdfDocumentRendererInterface $pdf the closed Dompdf runner
     * @param ReportBrandResolverInterface $brands brand of the report's context
     * @param TranslatorInterface $translator footer texts
     * @param RenderLimits $limits PDF row and cell caps
     * @param string $template `formats.pdf.template`
     * @param string $paper `formats.pdf.paper`
     * @param BrandAssetReaderInterface|null $assets reads the logo bytes when the host has a reader
     */
    public function __construct(
        private PdfDocumentRendererInterface $pdf,
        private ReportBrandResolverInterface $brands,
        private TranslatorInterface $translator,
        private RenderLimits $limits,
        private string $template = '@NowoReportKit/pdf/report.html.twig',
        private string $paper = 'A4',
        private ?BrandAssetReaderInterface $assets = null,
    ) {
    }

    /**
     * PDF.
     */
    public function format(): ReportFormat
    {
        return ReportFormat::Pdf;
    }

    /**
     * @param resource $stream
     */
    public function render(Report $report, RenderBudget $budget, mixed $stream): void
    {
        $brand    = $this->brands->brandFor($report->brandContext);
        $accent   = ReportChartSvg::colour($brand->light->primary);
        $sections = [];
        foreach ($report->sections as $section) {
            $budget->check();
            $sections[] = PdfSectionView::build($section, $accent, $this->limits->pdfMaxRows, $this->limits->pdfMaxCell);
        }
        $budget->check();
        $options = new PdfOptions(
            $this->paper,
            false,
            mb_strimwidth($brand->name . ' · ' . $report->title, 0, 120, '…', 'UTF-8'),
            $this->translator->trans('report.page', ['%page%' => '{PAGE_NUM}', '%pages%' => '{PAGE_COUNT}'], 'NowoReportKitBundle', $report->locale),
            mb_strimwidth($report->title, 0, 70, '…', 'UTF-8') . ' · ' . mb_strimwidth($report->filters, 0, 120, '…', 'UTF-8'),
            deadline: $budget->deadline(),
        );
        fwrite($stream, $this->pdf->render($this->template, [
            'report'   => $report,
            'sections' => $sections,
            'brand'    => ['name' => $brand->name, 'accent' => $accent, 'logo' => $this->logo($brand)],
            'locale'   => $report->locale ?? $this->translator->getLocale(),
        ], $options));
        $budget->check();
    }

    /**
     * The brand mark (or light logo) as a data URI, when the host can read its bytes; never a file or a URL.
     */
    private function logo(Brand $brand): ?string
    {
        $asset = $brand->asset(BrandAssetSlot::MARK) ?? $brand->asset(BrandAssetSlot::LOGO_LIGHT);
        if (!$asset instanceof BrandAsset || !$this->assets instanceof BrandAssetReaderInterface) {
            return null;
        }
        try {
            return BrandAssetDataUri::fromBytes($asset, $this->assets->read($asset));
        } catch (BrandAssetReadException|InvalidArgumentException) {
            return null;
        }
    }
}
