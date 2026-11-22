<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Pdf;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Image\Cache;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\PdfDocumentRendererInterface;
use Nowo\ReportKitBundle\Contract\PdfOptions;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use Twig\Environment;

use function hexdec;
use function is_dir;
use function is_writable;
use function str_replace;
use function strlen;
use function substr;

/**
 * Default {@see PdfDocumentRendererInterface}: renders the Twig template, refuses HTML above
 * `limits.max_pdf_html_bytes`, then runs one Dompdf instance with {@see ClosedDompdfOptions} in a private per-call
 * directory removed in `finally`. Draws the footer texts and the running header of {@see PdfOptions}.
 *
 * Worker mode: one Dompdf per call, never kept; Dompdf's static image cache and its global warning list are cleared
 * in `finally` (a failed render would otherwise leave paths to the removed directory and grow the warning list).
 */
final readonly class DompdfDocumentRenderer implements PdfDocumentRendererInterface
{
    /** Page margin of the footer and header texts, in points. */
    private const MARGIN = 40;

    /** Size of the page texts, in points. */
    private const TEXT_SIZE = 7;

    /**
     * @param Environment $twig renders the template
     * @param PrivateTempDirectory $temp per-call directory
     * @param RenderLimits $limits HTML size guard
     * @param string $fontCache warmed font metrics cache directory
     */
    public function __construct(
        private Environment $twig,
        private PrivateTempDirectory $temp,
        private RenderLimits $limits,
        private string $fontCache,
    ) {
    }

    /**
     * The PDF bytes.
     *
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context, PdfOptions $options = new PdfOptions()): string
    {
        $html = $this->twig->render($template, $context);
        if (strlen($html) > $this->limits->maxPdfHtmlBytes) {
            throw new ReportLimitExceededException(ReportLimitExceededException::PDF_HTML_BYTES, $this->limits->maxPdfHtmlBytes);
        }

        $dir = $this->temp->create();
        try {
            $fontCache = is_dir($this->fontCache) && is_writable($this->fontCache) ? $this->fontCache : null;
            $dompdf    = new Dompdf(ClosedDompdfOptions::create($dir, $fontCache));
            try {
                $dompdf->setPaper($options->paper, $options->landscape ? 'landscape' : 'portrait');
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->render();
                $this->pageTexts($dompdf, $options);

                return (string) $dompdf->output();
            } finally {
                Cache::clear();
                DompdfWarnings::clear();
            }
        } finally {
            $this->temp->discard($dir);
        }
    }

    private function pageTexts(Dompdf $dompdf, PdfOptions $options): void
    {
        $canvas  = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font    = (string) $metrics->getFont(ClosedDompdfOptions::DEFAULT_FONT);
        $colour  = $this->rgb($options->footerColor);
        $width   = $canvas->get_width();
        $bottom  = $canvas->get_height() - 27;
        if ($options->footerLeft !== null) {
            $canvas->page_text(self::MARGIN, $bottom, $options->footerLeft, $font, self::TEXT_SIZE, $colour);
        }
        if ($options->footerRight !== null) {
            $sample = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], '999', $options->footerRight);
            $canvas->page_text($width - self::MARGIN - $metrics->getTextWidth($sample, $font, self::TEXT_SIZE), $bottom, $options->footerRight, $font, self::TEXT_SIZE, $colour);
        }
        $header = $options->runningHeader;
        if ($header !== null) {
            $canvas->page_script(static function (int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics) use ($header, $font, $colour, $width): void {
                if ($pageNumber > 1) {
                    $canvas->text(self::MARGIN, 22, $header, $font, self::TEXT_SIZE, $colour);
                    $canvas->line(self::MARGIN, 36, $width - self::MARGIN, 36, $colour, 0.4);
                }
            });
        }
    }

    /**
     * @return array{float, float, float}
     */
    private function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
    }
}
