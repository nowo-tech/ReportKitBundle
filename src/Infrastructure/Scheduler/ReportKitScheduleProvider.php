<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Scheduler;

use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The bundle's Symfony Scheduler recipe (contract §9, schedule `nowo_report_kit`, registered only with
 * `schedule.enabled` and symfony/scheduler installed): `schedule.cron` sends {@see DispatchDueReportsMessage}, and
 * every night {@see PruneReportRunsMessage}. The jobs only enqueue (REQ-MESSENGER-008). Consume it with
 * `messenger:consume scheduler_nowo_report_kit`.
 */
final readonly class ReportKitScheduleProvider implements ScheduleProviderInterface
{
    /** Schedule name (transport `scheduler_nowo_report_kit`). */
    public const NAME = 'nowo_report_kit';

    /** Nightly retention. */
    public const PRUNE_CRON = '17 3 * * *';

    /**
     * @param string $cron `schedule.cron`
     */
    public function __construct(private string $cron = '5 * * * *')
    {
    }

    /**
     * The two recurring messages.
     */
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron($this->cron, new DispatchDueReportsMessage()),
            RecurringMessage::cron(self::PRUNE_CRON, new PruneReportRunsMessage()),
        );
    }
}
