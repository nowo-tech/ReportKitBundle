<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;

/**
 * Closed Dompdf run of any Twig template or ready HTML (contract §4.2, Q-O10: public API for reports, invoices,
 * receipts, mandates). No remote resource, no JavaScript, no PHP, no `file://` access: only `data:` URIs load (embed
 * images as `data:image/...;base64,...`); a private temporary directory per call is the chroot and is removed in
 * `finally`. Paper size and orientation, page texts and the deadline come from {@see PdfOptions}.
 */
interface PdfDocumentRendererInterface
{
    /**
     * The PDF bytes.
     *
     * @param string $template Twig template name, e.g. `@NowoReportKit/pdf/report.html.twig`
     * @param array<string, mixed> $context template variables
     *
     * @throws ReportLimitExceededException when the HTML is larger than `limits.max_pdf_html_bytes`
     */
    public function render(string $template, array $context, PdfOptions $options = new PdfOptions()): string;

    /**
     * The PDF bytes of HTML the host already rendered (same closed setup, same limits).
     *
     * @throws ReportLimitExceededException when the HTML is larger than `limits.max_pdf_html_bytes` or the deadline passes
     */
    public function renderHtml(string $html, PdfOptions $options = new PdfOptions()): string;
}
