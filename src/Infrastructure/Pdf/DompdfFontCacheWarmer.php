<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Pdf;

use Dompdf\Dompdf;
use Dompdf\Image\Cache;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

use function is_dir;
use function mkdir;

/**
 * Builds Dompdf's font metrics cache at `cache:warmup` into `%kernel.cache_dir%/nowo_report_kit/fonts` (contract
 * §6.2): renders a one-line document with the closed options, so requests only read the cache.
 *
 * @internal
 */
final readonly class DompdfFontCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param PrivateTempDirectory $temp per-call directory of the probe render
     * @param string $fontCache the cache directory to fill
     */
    public function __construct(private PrivateTempDirectory $temp, private string $fontCache)
    {
    }

    /**
     * Optional: a missing cache only makes the first render slower.
     */
    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if (!is_dir($this->fontCache)) {
            @mkdir($this->fontCache, 0775, true);
        }
        $dir = $this->temp->create();
        try {
            $state  = DompdfPhpState::snapshot();
            $dompdf = new Dompdf(ClosedDompdfOptions::create($dir, is_dir($this->fontCache) ? $this->fontCache : null));
            try {
                // Every family and style the default report template uses (audit ZG-I1).
                $dompdf->loadHtml('<p style="font-family: \'DejaVu Sans\'">Aa <b>Bb</b> <i>Cc</i> <b><i>Dd</i></b></p><p style="font-family: \'DejaVu Sans Mono\'">Ee <b>Ff</b></p>', 'UTF-8');
                $dompdf->render();
            } finally {
                Cache::clear();
                DompdfWarnings::clear();
                DompdfPhpState::restore($state);
            }
        } finally {
            $this->temp->discard($dir);
        }

        return [];
    }
}
