<?php

declare(strict_types=1);

namespace App\Report;

use function crc32;

/**
 * Deterministic demo data (no table): the same project and day always give the same figures.
 */
final class DemoData
{
    /** Demo projects: id => name. */
    public const PROJECTS = ['alpha' => 'Alpha shop', 'beta' => 'Beta app'];

    /** Order statuses. */
    public const STATUSES = ['paid', 'shipped', 'refunded', 'cancelled'];

    /**
     * A number from 0 to $max - 1 for the given key.
     */
    public static function figure(string $key, int $max): int
    {
        return crc32($key) % $max;
    }
}
