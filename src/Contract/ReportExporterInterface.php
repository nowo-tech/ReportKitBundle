<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\FormatNotEnabledException;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;

/**
 * Renders a report in one format (contract §4.2). The Presentation layer wraps the file in a private, `no-store`
 * response; a scheduled run attaches it to a mail.
 */
interface ReportExporterInterface
{
    /**
     * @throws FormatNotEnabledException when the format is off or its engine is missing
     * @throws ReportLimitExceededException when the render goes over `limits.render_seconds` or `max_pdf_html_bytes`
     */
    public function export(Report $report, ReportFormat $format): RenderedFile;

    /**
     * The formats this exporter renders right now (configuration, installed engines and the settings provider).
     *
     * @return list<ReportFormat>
     */
    public function formats(): array;
}
