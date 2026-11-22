<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Export;

use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportFormat;

/**
 * One format renderer behind the exporter. Stateless: every engine instance and temporary file lives inside one call.
 *
 * @internal
 */
interface ReportRendererInterface
{
    /**
     * The format it renders.
     */
    public function format(): ReportFormat;

    /**
     * The file bytes, written into $stream (a `php://temp` stream).
     *
     * @param resource $stream
     */
    public function render(Report $report, RenderBudget $budget, mixed $stream): void;
}
