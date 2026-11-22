<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * Shared checks of the value objects (contract §4.1). Never trims: a value is valid as given or refused.
 *
 * @internal
 */
final class Guard
{
    /** Owner types, context types, report type keys and parameter names. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /**
     * Refuses a key that does not match {@see self::KEY_PATTERN}.
     */
    public static function key(string $field, string $value): string
    {
        if (preg_match(self::KEY_PATTERN, $value) !== 1) {
            throw new InvalidReportArgumentException(sprintf('%s must match ^[a-z][a-z0-9_]{0,31}$.', $field));
        }

        return $value;
    }

    /**
     * An identifier that references a person or a context (REQ-SEC-006 rule 1, audit QL-L2): 1 to $max UTF-8
     * characters, no control or invisible characters (format characters such as ZWSP, BOM and bidi marks, NBSP, line
     * and paragraph separators), no white space at either edge. Refused, never trimmed.
     */
    public static function reference(string $field, string $value, int $max): string
    {
        if ($value === ''
            || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > $max
            || preg_match('/^[\s\p{Z}]|[\s\p{Z}]$|[\p{Cc}\p{Cf}\x{00A0}\x{2028}\x{2029}]/uD', $value) === 1
        ) {
            throw new InvalidReportArgumentException(sprintf('%s must be 1 to %d UTF-8 characters without control or invisible characters and without white space at the edges.', $field, $max));
        }

        return $value;
    }
}
