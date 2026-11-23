<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Event;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\SubscriptionId;

/**
 * A scheduled run failed before the mail was handed over (contract §6.5), dispatched after the failure was recorded.
 * The error code names the failure class (`build_failed`, `render_failed`, `mail_failed`, `limit_exceeded`), never a
 * message.
 */
final readonly class ScheduledReportFailedEvent
{
    /**
     * @param DateTimeImmutable $slotAt the scheduled slot, UTC
     * @param int $consecutiveFailures failed runs in a row, this one included
     */
    public function __construct(
        public SubscriptionId $subscriptionId,
        public DateTimeImmutable $slotAt,
        public string $errorCode,
        public int $consecutiveFailures,
    ) {
    }
}
