<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Event;

use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\SubscriptionId;

/**
 * A subscription was created, paused, resumed (`enabled`) or deleted (contract §6.5), dispatched after the commit.
 * Ids only: no parameters, no e-mail address.
 */
final readonly class ReportSubscriptionChangedEvent
{
    public const CREATED = 'created';
    public const ENABLED = 'enabled';
    public const PAUSED  = 'paused';
    public const DELETED = 'deleted';

    /**
     * @param string $change one of the constants of this class
     * @param OwnerRef|null $actor who made the change (the owner for the owner's pages; null = the system)
     * @param PauseReason|null $reason the pause reason of a `paused` change
     */
    public function __construct(
        public SubscriptionId $subscriptionId,
        public OwnerRef $owner,
        public string $type,
        public string $change,
        public ?OwnerRef $actor,
        public ?PauseReason $reason = null,
    ) {
    }
}
