<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Export;

use Normalizer;
use Nowo\ReportKitBundle\Contract\ReportFormat;

use function class_exists;
use function mb_strtolower;
use function preg_replace;
use function rtrim;
use function substr;
use function trim;

/**
 * File names `<filename_prefix>-<report filename>.<ext>` slugged to `[a-z0-9-]` (contract §6.2): safe in
 * `Content-Disposition` and on every file system.
 *
 * @internal
 */
final class ReportFileName
{
    /** Longest name before the extension. */
    public const MAX_LENGTH = 120;

    /**
     * `report-orders-acme-2026-11-22.csv`.
     */
    public static function build(string $prefix, string $base, ReportFormat $format): string
    {
        $slug = self::slug($prefix . '-' . $base);

        return ($slug === '' ? 'report' : $slug) . '.' . $format->extension();
    }

    /**
     * Lower-case ASCII letters and digits separated by single dashes (accents dropped when ext-intl is present).
     */
    public static function slug(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = (string) Normalizer::normalize($text, Normalizer::FORM_D);
        }
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $text), 'UTF-8'));

        return rtrim(substr(trim($text, '-'), 0, self::MAX_LENGTH), '-');
    }
}
