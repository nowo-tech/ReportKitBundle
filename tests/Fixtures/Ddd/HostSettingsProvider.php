<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Ddd;

use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;

/**
 * DDD-host settings provider (rule 8): CSV and JSON only, whatever the configuration says.
 */
final readonly class HostSettingsProvider implements ReportSettingsProviderInterface
{
    public function enabled(): bool
    {
        return true;
    }

    public function formatsEnabled(): array
    {
        return [ReportFormat::Csv, ReportFormat::Json];
    }

    public function schedulesEnabled(): bool
    {
        return false;
    }
}
