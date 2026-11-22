<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Csv;

/**
 * CSV formula-injection guard (`REQ-QA-019`, contract §6.2): text cells a spreadsheet could evaluate are neutralised;
 * numbers and null stay as they are.
 *
 * @internal
 */
interface CsvCellGuardInterface
{
    /**
     * The guarded cells of one row.
     *
     * @param list<float|int|string|null> $row
     *
     * @return list<string>
     */
    public function row(array $row): array;
}
