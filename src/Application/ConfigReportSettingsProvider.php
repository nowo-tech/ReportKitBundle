<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application;

use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;

use function array_map;
use function array_values;

/**
 * Default {@see ReportSettingsProviderInterface}: the configuration values compiled into the container.
 */
final readonly class ConfigReportSettingsProvider implements ReportSettingsProviderInterface
{
    /**
     * @param bool $enabled `nowo_report_kit.enabled`
     * @param list<string> $formats the formats switched on and installed (format values)
     * @param bool $schedules `schedule.enabled`
     */
    public function __construct(private bool $enabled, private array $formats, private bool $schedules)
    {
    }

    /**
     * `nowo_report_kit.enabled`.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<ReportFormat>
     */
    public function formatsEnabled(): array
    {
        return array_values(array_map(ReportFormat::from(...), $this->formats));
    }

    /**
     * `schedule.enabled`.
     */
    public function schedulesEnabled(): bool
    {
        return $this->schedules;
    }
}
