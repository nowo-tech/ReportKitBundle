<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Filesystem;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function bin2hex;
use function is_dir;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function rtrim;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * A private directory (mode 0700, random name) for one render: Dompdf's chroot and temp dir, OpenSpout's temp folder.
 * Callers remove it in `finally` ({@see self::discard()}), whatever happens (fixes Beacon's XLSX temp-file leak in long-lived
 * workers).
 *
 * @internal
 */
final readonly class PrivateTempDirectory
{
    /** Name prefix of the directories. */
    public const PREFIX = 'nowo-report-kit-';

    /**
     * @param string|null $baseDir parent directory (`temp_dir`); null = the system temp dir, read per call
     */
    public function __construct(private ?string $baseDir = null)
    {
    }

    /**
     * Creates a fresh private directory and returns its path (no trailing slash). The caller removes it with
     * {@see self::discard()} in `finally`.
     */
    public function create(): string
    {
        $base = $this->baseDir ?? sys_get_temp_dir();
        $dir  = rtrim($base, '/\\') . '/' . self::PREFIX . bin2hex(random_bytes(12));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create a private temporary directory under "%s".', $base));
        }

        return $dir;
    }

    /**
     * Removes $dir and everything in it; symbolic links are removed, never followed.
     */
    public function discard(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $path = $item->getPathname();
            if ($item->isDir() && !is_link($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
