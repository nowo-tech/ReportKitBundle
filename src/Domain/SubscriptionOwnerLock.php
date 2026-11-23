<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Domain;

/**
 * The per-owner create lock (table `<prefix>report_owner_lock`, REQ-RUNTIME-003 rule 4): one row per owner that ever
 * created a subscription. {@see SubscriptionStoreInterface::lockOwner()} bumps it before the owner's subscriptions
 * are counted, so concurrent creates for one owner run one after the other and `subscriptions.max_per_owner` holds on
 * every database and isolation level. No business data.
 *
 * Plain PHP, mapped in XML for the schema only; written through DBAL. Not final: Doctrine generates proxies.
 */
class SubscriptionOwnerLock
{
    /**
     * Holds the column values.
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly int $version = 1,
    ) {
    }
}
