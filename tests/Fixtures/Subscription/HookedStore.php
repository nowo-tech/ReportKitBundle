<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Subscription;

use Closure;
use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Domain\ReportRun;
use Nowo\ReportKitBundle\Domain\Subscription;
use Nowo\ReportKitBundle\Domain\SubscriptionStoreInterface;

/**
 * Store decorator of the tests: delegates everything and runs `$afterTake` once after a run slot was taken, to
 * interleave another writer at that exact point.
 */
final class HookedStore implements SubscriptionStoreInterface
{
    public ?Closure $afterTake = null;

    public function __construct(private readonly SubscriptionStoreInterface $inner)
    {
    }

    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed
    {
        return $this->inner->transactional($work, $afterCommit);
    }

    public function lockOwner(OwnerRef $owner): void
    {
        $this->inner->lockOwner($owner);
    }

    public function ofOwner(OwnerRef $owner, int $limit): array
    {
        return $this->inner->ofOwner($owner, $limit);
    }

    public function find(string $id, bool $forUpdate = false): ?Subscription
    {
        return $this->inner->find($id, $forUpdate);
    }

    public function insert(Subscription $subscription): void
    {
        $this->inner->insert($subscription);
    }

    public function update(string $id, array $values, DateTimeImmutable $now, ?int $expectedVersion = null): bool
    {
        return $this->inner->update($id, $values, $now, $expectedVersion);
    }

    public function delete(string $id, ?int $expectedVersion = null): bool
    {
        return $this->inner->delete($id, $expectedVersion);
    }

    public function due(DateTimeImmutable $now, int $limit, ?string $after): array
    {
        return $this->inner->due($now, $limit, $after);
    }

    public function isTransactionActive(): bool
    {
        return $this->inner->isTransactionActive();
    }

    public function claim(string $id, DateTimeImmutable $slot, DateTimeImmutable $next, string $token, DateTimeImmutable $now, DateTimeImmutable $stale): bool
    {
        return $this->inner->claim($id, $slot, $next, $token, $now, $stale);
    }

    public function insertRun(ReportRun $run): bool
    {
        $taken = $this->inner->insertRun($run);
        if ($taken && $this->afterTake instanceof Closure) {
            $hook            = $this->afterTake;
            $this->afterTake = null;
            $hook();
        }

        return $taken;
    }

    public function takeRun(string $subscriptionId, DateTimeImmutable $slot, DateTimeImmutable $now, DateTimeImmutable $stale, string $lease): bool
    {
        return $this->inner->takeRun($subscriptionId, $slot, $now, $stale, $lease);
    }

    public function findRun(string $subscriptionId, DateTimeImmutable $slot): ?ReportRun
    {
        return $this->inner->findRun($subscriptionId, $slot);
    }

    public function updateRun(string $subscriptionId, DateTimeImmutable $slot, array $values, array $whereStatuses = [], ?string $lease = null): bool
    {
        return $this->inner->updateRun($subscriptionId, $slot, $values, $whereStatuses, $lease);
    }

    public function forgetOwner(OwnerRef $owner): array
    {
        return $this->inner->forgetOwner($owner);
    }

    public function oldRuns(DateTimeImmutable $before, int $limit, ?string $after): array
    {
        return $this->inner->oldRuns($before, $limit, $after);
    }

    public function deleteRuns(array $ids): int
    {
        return $this->inner->deleteRuns($ids);
    }
}
