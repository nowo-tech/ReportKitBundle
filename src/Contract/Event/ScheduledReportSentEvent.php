<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Event;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\SubscriptionId;

/**
 * A scheduled mail was handed over and its run row committed (contract §6.5). No content, no address.
 */
final readonly class ScheduledReportSentEvent
{
    /**
     * @param DateTimeImmutable $slotAt the scheduled slot, UTC
     * @param list<ReportFormat> $formats the attached formats (empty when the files were too large)
     * @param int $bytes the attachments' total size
     */
    public function __construct(
        public SubscriptionId $subscriptionId,
        public OwnerRef $owner,
        public DateTimeImmutable $slotAt,
        public array $formats,
        public int $bytes,
    ) {
    }
}
