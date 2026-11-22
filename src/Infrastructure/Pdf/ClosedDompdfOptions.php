<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Pdf;

use Dompdf\Options;

/**
 * The one place the Dompdf options are set (contract §6.2, audit ZE-I5). Closed:
 *
 * - `isRemoteEnabled` false, `isJavascriptEnabled` false, `isPhpEnabled` false;
 * - allowed protocols: **`data://` only** (no `file://`, `http://`, `https://`, `phar://`), so no image, stylesheet or
 *   font is read from the file system or the network, not even inside Dompdf's own root;
 * - chroot and temp dir: the private per-call directory (empty, removed after the call);
 * - fonts: Dompdf's bundled fonts, metrics cache in `%kernel.cache_dir%/nowo_report_kit/fonts` (built at warmup) or,
 *   when that is not writable, in the per-call directory;
 * - the HTML5 parser stays on (Dompdf default).
 *
 * @internal
 */
final class ClosedDompdfOptions
{
    /** Default font (bundled with Dompdf, covers Latin, Greek and Cyrillic). */
    public const DEFAULT_FONT = 'DejaVu Sans';

    /**
     * The closed options for a render in $privateDir.
     *
     * @param string $privateDir the per-call directory (chroot and temp dir)
     * @param string|null $fontCache the warmed font metrics cache; null = $privateDir
     */
    public static function create(string $privateDir, ?string $fontCache = null): Options
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $options->setChroot([$privateDir]);
        $options->setTempDir($privateDir);
        $options->setFontCache($fontCache ?? $privateDir);
        $options->setDefaultFont(self::DEFAULT_FONT);
        $options->setIsHtml5ParserEnabled(true);
        $options->setIsFontSubsettingEnabled(true);

        return $options;
    }
}
