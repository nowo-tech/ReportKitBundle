<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Event;

use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportFormat;

/**
 * An export response was built (contract §6.5, usage and audit): who, which type and format, which effective context,
 * how many rows and bytes. Never the content or the parameter values.
 */
final readonly class ReportExportedEvent
{
    /**
     * @param OwnerRef $owner the person who exported
     * @param string $type the report type key
     * @param ReportFormat $format the file format
     * @param ContextRef|null $context the effective context (null = every accessible context, or none)
     * @param int $rows table rows over every section
     * @param int $bytes file size
     */
    public function __construct(
        public OwnerRef $owner,
        public string $type,
        public ReportFormat $format,
        public ?ContextRef $context,
        public int $rows,
        public int $bytes,
    ) {
    }
}
