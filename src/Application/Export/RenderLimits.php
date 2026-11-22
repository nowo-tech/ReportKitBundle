<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Export;

/**
 * The `limits.*` that bound a render (REQ-RUNTIME-001, contract §6.2), compiled from the configuration.
 *
 * @internal
 */
final readonly class RenderLimits
{
    /**
     * @param int $maxRowsPerSection rows per section in CSV, XLSX and JSON
     * @param int $pdfMaxRows rows per table in the PDF
     * @param int $pdfMaxCell characters per text cell in the PDF
     * @param int $renderSeconds time budget of one render, checked between sections
     * @param int $maxPdfHtmlBytes largest HTML handed to Dompdf
     */
    public function __construct(
        public int $maxRowsPerSection = 50000,
        public int $pdfMaxRows = 120,
        public int $pdfMaxCell = 180,
        public int $renderSeconds = 25,
        public int $maxPdfHtmlBytes = 5242880,
    ) {
    }
}
