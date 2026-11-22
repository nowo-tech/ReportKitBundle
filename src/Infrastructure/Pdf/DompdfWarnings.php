<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Pdf;

/**
 * Dompdf records warnings in the global `$_dompdf_warnings` list and never empties it: in a long-lived worker that
 * list would grow with every render. Cleared after each render.
 *
 * @internal
 */
final class DompdfWarnings
{
    /**
     * Empties Dompdf's global warning list.
     */
    public static function clear(): void
    {
        // @igor-ignore - Dompdf's own process-global list; this write EMPTIES it after every render (worker memory).
        $GLOBALS['_dompdf_warnings'] = []; // @phpstan-ignore frankenphp.worker.noGlobalStateWrite (the write removes state Dompdf left)
    }
}
