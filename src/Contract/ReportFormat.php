<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * The export formats (route requirement `csv|xlsx|pdf|json`).
 */
enum ReportFormat: string
{
    case Csv  = 'csv';
    case Xlsx = 'xlsx';
    case Pdf  = 'pdf';
    case Json = 'json';

    /** Route requirement of every format. */
    public const REQUIREMENT = 'csv|xlsx|pdf|json';

    /**
     * The `Content-Type` of a file in this format.
     */
    public function contentType(): string
    {
        return match ($this) {
            self::Csv  => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf  => 'application/pdf',
            self::Json => 'application/json',
        };
    }

    /**
     * The file name extension, without the dot.
     */
    public function extension(): string
    {
        return $this->value;
    }
}
