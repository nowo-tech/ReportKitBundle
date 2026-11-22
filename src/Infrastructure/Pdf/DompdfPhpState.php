<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Pdf;

use function ini_get;
use function ini_set;
use function mb_internal_encoding;
use function setlocale;

use const LC_NUMERIC;

/**
 * Dompdf's `render()` sets `LC_NUMERIC` to `C`, `pcre.jit` to 0 and the mbstring encoding to UTF-8, and restores them
 * only when it ends normally (no `finally` in dompdf 3.1). A render that throws (a layout error, the time budget)
 * would leave them changed for the rest of the worker's life (audit ZG-L2). The renderer takes a snapshot before
 * Dompdf runs and restores it in `finally`: these calls only put back the process values that were there before.
 *
 * @internal
 */
final class DompdfPhpState
{
    /**
     * The current values.
     *
     * @return array{locale: false|string, jit: false|string, encoding: string}
     */
    public static function snapshot(): array
    {
        return ['locale' => setlocale(LC_NUMERIC, '0'), 'jit' => ini_get('pcre.jit'), 'encoding' => mb_internal_encoding()];
    }

    /**
     * Puts back a snapshot.
     *
     * @param array{locale: false|string, jit: false|string, encoding: string} $state
     */
    public static function restore(array $state): void
    {
        // @igor-ignore - restores the process values Dompdf changed during the render; never sets new ones.
        if ($state['locale'] !== false) {
            setlocale(LC_NUMERIC, $state['locale']); // @phpstan-ignore frankenphp.worker.noSetLocale (restores the value Dompdf changed)
        }
        if ($state['jit'] !== false) {
            ini_set('pcre.jit', $state['jit']);
        }
        if ($state['encoding'] !== '') {
            mb_internal_encoding($state['encoding']); // @phpstan-ignore frankenphp.worker.noMbEncodingMutation (restores the value Dompdf changed)
        }
    }
}
