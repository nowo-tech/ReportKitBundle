<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;

/**
 * Closed Dompdf run of any Twig template (contract §4.2, Q-O10: public API for reports, invoices, receipts,
 * mandates). No remote resource, no JavaScript, no PHP, no `file://` access: only `data:` URIs load; a private
 * temporary directory per call is the chroot and is removed in `finally`.
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
}
