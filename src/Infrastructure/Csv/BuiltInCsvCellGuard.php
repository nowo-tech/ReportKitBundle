<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Csv;

use function array_map;
use function is_string;
use function preg_match;
use function preg_replace;

/**
 * The bundle's own guard (Q-O4 fallback when ControllerKitBundle is absent), same rules and same test table as
 * ControllerKit's `CsvSafeWriter::cell()`: a text cell starting with `=`, `+`, `-`, `@`, tab or CR gets a leading
 * `'`; a trigger after a likely list separator (`;`, `,`, tab, `|`, CR, LF) gets a space before it (Excel splits on
 * the system separator and would evaluate the second part). Numbers are never formulas and stay as they are.
 *
 * @internal
 */
final readonly class BuiltInCsvCellGuard implements CsvCellGuardInterface
{
    /** First characters a spreadsheet may evaluate as a formula (OWASP CSV injection). */
    public const FORMULA_PREFIX_PATTERN = '/^[=+\-@\t\r]/';

    /** A likely list separator or line break followed by a formula trigger. */
    public const SEPARATOR_FORMULA_PATTERN = '/([;,\t|\r\n])(?=[=+\-@\t\r])/';

    /**
     * @param list<float|int|string|null> $row
     *
     * @return list<string>
     */
    public function row(array $row): array
    {
        return array_map($this->cell(...), $row);
    }

    private function cell(int|float|string|null $value): string
    {
        if (!is_string($value)) {
            return (string) $value;
        }
        $guarded = (string) preg_replace(self::SEPARATOR_FORMULA_PATTERN, '$1 ', $value);

        return preg_match(self::FORMULA_PREFIX_PATTERN, $guarded) === 1 ? "'" . $guarded : $guarded;
    }
}
