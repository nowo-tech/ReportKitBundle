<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Message;

/**
 * Claim the due subscriptions and enqueue one {@see RunScheduledReportMessage} per claimed row (contract §9). Sent by
 * the bundle's schedule (`schedule.cron`) or `nowo:report-kit:dispatch-due`; a pass that runs out of
 * `messenger.max_seconds` dispatches the rest as a new message with the keyset cursor. Scalars only.
 */
final readonly class DispatchDueReportsMessage
{
    /**
     * @param string|null $after keyset cursor: continue after this subscription id (null = from the start)
     */
    public function __construct(public ?string $after = null)
    {
    }
}
