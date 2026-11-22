<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Csv;

use Nowo\ControllerKitBundle\Http\CsvSafeWriter;

/**
 * Q-O4: ControllerKit's `CsvSafeWriter` when ControllerKitBundle is installed (`bridges.controller_kit`), so every
 * CSV of the platform shares one audited guard.
 *
 * @internal
 */
final readonly class ControllerKitCsvCellGuard implements CsvCellGuardInterface
{
    /**
     * @param list<float|int|string|null> $row
     *
     * @return list<string>
     */
    public function row(array $row): array
    {
        return CsvSafeWriter::row($row);
    }
}
