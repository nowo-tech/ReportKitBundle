<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Domain;

use Closure;
use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Exception\ReportKitConflictException;
use Nowo\ReportKitBundle\Contract\OwnerRef;

/**
 * Persistence port of the subscriptions and runs (contract §5, §9). The default is DBAL on the connection of
 * `doctrine.entity_manager`; never an EntityManager flush. Owner-scoped reads are re-checked exactly in PHP.
 *
 * @internal
 */
interface SubscriptionStoreInterface
{
    /**
     * Runs `$work` in one transaction (joins a host transaction on the same connection), then `$afterCommit` with its
     * result once the outermost transaction committed. Lock conflicts are replayed (not inside a host transaction),
     * then thrown as {@see ReportKitConflictException}.
     *
     * @template T
     *
     * @param Closure(): T $work replayable
     * @param (Closure(T): void)|null $afterCommit
     *
     * @return T
     */
    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed;

    /**
     * Takes the owner's create lock row (inside {@see self::transactional()}).
     */
    public function lockOwner(OwnerRef $owner): void;

    /**
     * The owner's subscriptions, oldest first, exact owner only.
     *
     * @return list<Subscription>
     */
    public function ofOwner(OwnerRef $owner, int $limit): array;

    /**
     * One subscription by exact id; `$forUpdate` locks the row until the end of the transaction.
     */
    public function find(string $id, bool $forUpdate = false): ?Subscription;

    /**
     * Inserts a new row.
     */
    public function insert(Subscription $subscription): void;

    /**
     * Updates columns of one row and bumps its version (`version = version + 1`, `updated_at = $now`).
     *
     * @param array<string, mixed> $values column => PHP value (dates, booleans, arrays as JSON)
     * @param int|null $expectedVersion only when the row still has this version
     *
     * @return bool whether a row was updated
     */
    public function update(string $id, array $values, DateTimeImmutable $now, ?int $expectedVersion = null): bool;

    /**
     * Deletes one row.
     *
     * @param int|null $expectedVersion only when the row still has this version
     */
    public function delete(string $id, ?int $expectedVersion = null): bool;

    /**
     * Enabled subscriptions due at `$now` (their slot is `nextRunAt`), by id, after the keyset cursor.
     *
     * @return list<Subscription>
     */
    public function due(DateTimeImmutable $now, int $limit, ?string $after): array;

    /**
     * Whether the connection is inside a transaction (a host's, or Messenger's `doctrine_transaction` middleware).
     */
    public function isTransactionActive(): bool;

    /**
     * The atomic claim (contract §9): one conditional UPDATE that wins only while the row is enabled, still at
     * `$slot`, and not claimed (or claimed before `$stale`). Moves `next_run_at` to `$next`.
     */
    public function claim(string $id, DateTimeImmutable $slot, DateTimeImmutable $next, string $token, DateTimeImmutable $now, DateTimeImmutable $stale): bool;

    /**
     * Inserts the run row of a slot; false when the slot already has one (unique `(subscription_id, slot_at)`).
     */
    public function insertRun(ReportRun $run): bool;

    /**
     * Takes the lease of an existing run row of a slot that a failed or stale attempt left (status `running` with
     * no lease or a lease older than `$stale`, or `failed`). Exactly one caller wins.
     */
    public function takeRun(string $subscriptionId, DateTimeImmutable $slot, DateTimeImmutable $now, DateTimeImmutable $stale): bool;

    /**
     * The run row of a slot.
     */
    public function findRun(string $subscriptionId, DateTimeImmutable $slot): ?ReportRun;

    /**
     * Updates the run row of a slot.
     *
     * @param array<string, mixed> $values column => PHP value
     * @param string|null $whereStatus only while the row has this status
     */
    public function updateRun(string $subscriptionId, DateTimeImmutable $slot, array $values, ?string $whereStatus = null): bool;

    /**
     * Ids of run rows created before `$before`, by id, after the keyset cursor.
     *
     * @return list<string>
     */
    public function oldRuns(DateTimeImmutable $before, int $limit, ?string $after): array;

    /**
     * Deletes run rows by id.
     *
     * @param list<string> $ids
     */
    public function deleteRuns(array $ids): int;
}
