<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * The report types of the application, in the configured menu order, without the disabled ones.
 */
interface ReportTypeRegistryInterface
{
    /**
     * @return array<string, ReportTypeInterface> keyed by type key, in menu order
     */
    public function all(): array;

    /**
     * The type with this key; null when unknown or disabled.
     */
    public function get(string $key): ?ReportTypeInterface;
}
