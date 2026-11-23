<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Event;

use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\SubscriptionId;

/**
 * A scheduled run paused its subscription (contract §6.5): the owner is inactive, lost access, the type was removed,
 * or the runs failed `schedule.max_failures` times in a row. Dispatched after the commit; the NotifyKit bridge (S4)
 * turns it into a bell row for the owner.
 */
final readonly class ScheduledReportPausedEvent
{
    /**
     * Holds the ids and the reason.
     */
    public function __construct(
        public SubscriptionId $subscriptionId,
        public OwnerRef $owner,
        public PauseReason $reason,
    ) {
    }
}
