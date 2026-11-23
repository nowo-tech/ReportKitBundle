<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Message;

/**
 * Run one claimed slot of one subscription (contract §9): build as the owner, render, mail, record. Idempotent per
 * `(subscriptionId, slotAt)`: a redelivery after the mail was handed over never sends again; a redelivery after a
 * failure before the hand-over runs it again. Scalars only (no serialized objects).
 */
final readonly class RunScheduledReportMessage
{
    /**
     * @param string $subscriptionId the subscription id (UUID)
     * @param string $slotAt the claimed slot, UTC, `Y-m-d\TH:i:s\Z`
     * @param string $claimToken the token of the claim that produced this message
     */
    public function __construct(public string $subscriptionId, public string $slotAt, public string $claimToken)
    {
    }
}
