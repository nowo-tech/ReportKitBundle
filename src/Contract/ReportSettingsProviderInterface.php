<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Runtime switches (architecture bar rule 8): the default reads the configuration; a host may read them from its own
 * settings store. Called per use, never memoised by the bundle.
 */
interface ReportSettingsProviderInterface
{
    /**
     * Whether reports are on at all.
     */
    public function enabled(): bool;

    /**
     * The formats a viewer may export right now (a subset of the formats whose engine is installed).
     *
     * @return list<ReportFormat>
     */
    public function formatsEnabled(): array;

    /**
     * Whether scheduled subscriptions run (S3).
     */
    public function schedulesEnabled(): bool;
}
