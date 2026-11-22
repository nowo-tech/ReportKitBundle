<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures;

use function file;
use function file_put_contents;
use function is_file;
use function stream_wrapper_register;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;
use function sys_get_temp_dir;
use function unlink;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;

/**
 * Stands in for the `http` / `https` stream wrappers during the Dompdf-closed tests and logs every open to a file: a
 * closed renderer must never open one.
 */
final class SpyStreamWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path): bool
    {
        file_put_contents(self::log(), $path . "\n", FILE_APPEND);

        return false;
    }

    public function url_stat(string $path): false
    {
        file_put_contents(self::log(), $path . "\n", FILE_APPEND);

        return false;
    }

    public static function install(): void
    {
        @unlink(self::log());
        foreach (['http', 'https'] as $protocol) {
            stream_wrapper_unregister($protocol);
            stream_wrapper_register($protocol, self::class);
        }
    }

    /**
     * Restores the real wrappers and returns every path that was opened.
     *
     * @return list<string>
     */
    public static function uninstall(): array
    {
        foreach (['http', 'https'] as $protocol) {
            stream_wrapper_restore($protocol);
        }
        $opened = is_file(self::log()) ? (file(self::log(), FILE_IGNORE_NEW_LINES) ?: []) : [];
        @unlink(self::log());

        return $opened;
    }

    private static function log(): string
    {
        return sys_get_temp_dir() . '/nowo-report-kit-spy-wrapper.log';
    }
}
