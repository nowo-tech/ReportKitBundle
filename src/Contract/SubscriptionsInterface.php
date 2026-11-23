<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\ReportKitConflictException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionChangedException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionLimitException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;

/**
 * Owner-scoped subscriptions (contract §4.2, S3). Every call is one unit of work in the bundle's own transaction on
 * the connection of `doctrine.entity_manager` (it joins a host transaction on that connection; the events then wait
 * for the host's commit). Lookups go by id **and** owner, re-checked exactly in PHP: another owner's id behaves as a
 * missing one. Events ({@see Event\ReportSubscriptionChangedEvent}) are dispatched after the commit.
 *
 * The operator methods (`setEnabledAsOperator()`, `page()`) and `forgetContext()` come with S4.
 */
interface SubscriptionsInterface
{
    /**
     * Creates an enabled subscription whose first slot is the next one after now.
     *
     * The caller has already checked that the owner may open the type with these parameters (the report page does,
     * through the builder); every run checks again as the owner. At most `subscriptions.max_per_owner` per owner,
     * enforced under a per-owner lock row (concurrent creates run one after the other).
     *
     * @param list<ReportFormat> $formats extra formats; PDF is added when it is enabled (Q-O7), formats that are not
     *                                    enabled now are refused
     *
     * @throws UnknownReportTypeException the type does not exist or is disabled
     * @throws InvalidReportArgumentException the frequency is not allowed, or a format is not enabled
     * @throws SubscriptionLimitException the owner already has `max_per_owner` subscriptions
     * @throws ReportKitConflictException a lock conflict (409 + CONFLICT_001 / CONFLICT_002)
     */
    public function create(OwnerRef $owner, string $type, ReportParameters $parameters, Schedule $schedule, array $formats): SubscriptionView;

    /**
     * The owner's subscriptions, oldest first (at most `subscriptions.max_per_owner` plus any created before the cap
     * was lowered; bounded by 100).
     *
     * @return list<SubscriptionView>
     */
    public function ofOwner(OwnerRef $owner): array;

    /**
     * One of the owner's subscriptions; null when it does not exist or belongs to someone else (the pages answer 404).
     */
    public function get(OwnerRef $owner, SubscriptionId $id): ?SubscriptionView;

    /**
     * Pauses (`PauseReason::Owner`) or resumes a subscription. Resuming clears the pause reason and the failure count
     * and moves the next slot to the first one after now (a long pause never fires a burst of old slots).
     *
     * @param int|null $expectedVersion the {@see SubscriptionView::$version} the owner saw; null = no check
     *
     * @throws SubscriptionChangedException the row changed since `$expectedVersion` (409)
     * @throws ReportKitConflictException a lock conflict
     *
     * @return bool false when the subscription does not exist or is not the owner's (404)
     */
    public function setEnabled(OwnerRef $owner, SubscriptionId $id, bool $enabled, ?int $expectedVersion = null): bool;

    /**
     * Deletes a subscription. Its run history stays until retention removes it (`retention.run_days`).
     *
     * @param int|null $expectedVersion the {@see SubscriptionView::$version} the owner saw; null = no check
     *
     * @throws SubscriptionChangedException the row changed since `$expectedVersion` (409)
     * @throws ReportKitConflictException a lock conflict
     *
     * @return bool false when the subscription does not exist or is not the owner's (404)
     */
    public function delete(OwnerRef $owner, SubscriptionId $id, ?int $expectedVersion = null): bool;
}
