<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Domain\ReportRun;
use Nowo\ReportKitBundle\Domain\Subscription;
use Nowo\ReportKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ReportKitBundle\Infrastructure\Persistence\DbalSubscriptionStore;
use Nowo\ReportKitBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\ConnectionRegistry;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\Schema;
use PHPUnit\Framework\TestCase;
use stdClass;

use const DATE_ATOM;

/**
 * {@see DbalSubscriptionStore} details: the connection lookup, damaged rows, the one reconnect, the row lock SQL, the
 * run rows and retention queries.
 */
final class SubscriptionStoreTest extends TestCase
{
    private Connection $connection;

    private MemoryLogger $logger;

    private DbalSubscriptionStore $store;

    protected function setUp(): void
    {
        $this->connection = Schema::sqlite();
        $manager          = Schema::create($this->connection);
        $this->logger     = new MemoryLogger();
        // Through the entity manager name, as a standard host configures it.
        $this->store = new DbalSubscriptionStore(new ConnectionRegistry($this->connection, $manager), new AfterCommitQueue(), 'default', 'nowo_', $this->logger);
    }

    public function testRowsRoundTripAndUpdatesBumpTheVersion(): void
    {
        $row = $this->subscription('01933b1c-5d2e-7a3b-8c4d-00000000a001');
        $this->store->insert($row);
        $found = $this->store->find($row->id);
        self::assertNotNull($found);
        self::assertEquals($row, $found);
        self::assertNull($this->store->find('01933B1C-5D2E-7A3B-8C4D-00000000A001'), 'exact id only');

        $later = new DateTimeImmutable('2026-11-24T06:00:00Z');
        self::assertTrue($this->store->update($row->id, ['last_status' => 'sent', 'last_error_code' => null, 'enabled' => false, 'consecutive_failures' => 2, 'formats' => ['csv'], 'last_run_at' => $later], $later, 1));
        self::assertFalse($this->store->update($row->id, ['last_status' => 'x'], $later, 1), 'stale version');
        $updated = $this->store->find($row->id, true);
        self::assertNotNull($updated);
        self::assertSame([2, 'sent', false, 2, ['csv']], [$updated->version, $updated->lastStatus, $updated->enabled, $updated->consecutiveFailures, $updated->formats]);
        self::assertFalse($this->store->delete($row->id, 1));
        self::assertTrue($this->store->delete($row->id, 2));
    }

    public function testDamagedRowsAreSkippedAndLogged(): void
    {
        $this->store->insert($this->subscription('01933b1c-5d2e-7a3b-8c4d-00000000a001'));
        $this->store->insert($this->subscription('01933b1c-5d2e-7a3b-8c4d-00000000a002'));
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET next_run_at = 'not a date', formats = 'not json', parameters = '[1' WHERE id LIKE '%a001'");
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET next_run_at = '2026-11-24T06:00:00+00:00', formats = '\"pdf\"' WHERE id LIKE '%a002'");

        $owned = $this->store->ofOwner(new OwnerRef('user', 'alice'), 10);
        self::assertCount(1, $owned);
        self::assertSame('2026-11-24T06:00:00+00:00', $owned[0]->nextRunAt->format(DATE_ATOM), 'a stored ISO date is still read');
        self::assertSame([], $owned[0]->formats);
        self::assertStringContainsString('damaged subscription row', $this->logger->records[0]['message']);
        self::assertNull($this->store->find('01933b1c-5d2e-7a3b-8c4d-00000000a001'));
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET weekday = 'x' WHERE id LIKE '%a002'");
        self::assertSame([], $this->store->ofOwner(new OwnerRef('user', 'alice'), 10));
    }

    public function testRunRowsAreUniquePerSlotAndPrunedById(): void
    {
        $slot = new DateTimeImmutable('2026-11-24T06:00:00Z');
        self::assertTrue($this->store->insertRun($this->runRow('r1', $slot)));
        self::assertFalse($this->store->insertRun($this->runRow('r2', $slot)), 'one run per slot');
        self::assertTrue($this->store->insertRun($this->runRow('r3', $slot->modify('+1 day'))));
        self::assertFalse($this->store->takeRun('s1', $slot, $slot, $slot->modify('-30 minutes')), 'a fresh lease is held');
        self::assertTrue($this->store->updateRun('s1', $slot, ['status' => 'failed', 'lease_at' => null], 'running'));
        self::assertFalse($this->store->updateRun('s1', $slot, ['status' => 'sent'], 'running'));
        self::assertTrue($this->store->takeRun('s1', $slot, $slot, $slot->modify('-30 minutes')));
        $run = $this->store->findRun('s1', $slot);
        self::assertNotNull($run);
        self::assertSame(['running', 2], [$run->status, $run->attempts]);
        self::assertNull($this->store->findRun('S1', $slot));

        self::assertSame(['r1', 'r3'], $this->store->oldRuns($slot->modify('+5 days'), 10, null));
        self::assertSame(['r3'], $this->store->oldRuns($slot->modify('+5 days'), 10, 'r1'));
        self::assertSame(0, $this->store->deleteRuns([]));
        self::assertSame(2, $this->store->deleteRuns(['r1', 'r3']));

        $this->connection->insert('nowo_report_run', ['id' => 'r9', 'subscription_id' => 's9', 'owner_type' => 'user', 'owner_id' => 'alice', 'slot_at' => '2026-11-24 06:00:00', 'status' => 'sent', 'formats' => '[]', 'bytes' => 'x', 'duration_ms' => 0, 'attempts' => 1, 'created_at' => '2026-11-24 06:00:00']);
        self::assertNull($this->store->findRun('s9', $slot));
        self::assertStringContainsString('damaged run row', $this->logger->records[0]['message']);
    }

    public function testALostConnectionIsRetriedOnceOutsideATransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL84Platform());
        $connection->expects(self::once())->method('close');
        $connection->expects(self::exactly(2))->method('fetchAssociative')
            ->with(self::stringEndsWith('WHERE id = ? FOR UPDATE'))
            ->willReturnOnConsecutiveCalls(self::throwException($this->lost()), false);
        self::assertNull($this->storeOn($connection)->find('x', true));
    }

    public function testARowWithWrongTypesIsDamaged(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL84Platform());
        $connection->method('fetchAssociative')->willReturn(['id' => 5, 'parameters' => null, 'formats' => null]);
        self::assertNull($this->storeOn($connection)->find('5'));
        self::assertStringContainsString('Column "id" is not a string', $this->logger->records[0]['context']['message']);

        $nulls = $this->createMock(Connection::class);
        $nulls->method('isTransactionActive')->willReturn(false);
        $nulls->method('getDatabasePlatform')->willReturn(new MySQL84Platform());
        $nulls->method('fetchAssociative')->willReturn([
            'id'              => 'x', 'owner_type' => 'user', 'owner_id' => 'alice', 'report_type' => 'orders', 'parameters' => null, 'frequency' => 'daily',
            'weekday'         => 1, 'month_day' => 1, 'hour' => 7, 'timezone' => 'UTC', 'formats' => null, 'enabled' => 1, 'pause_reason' => null,
            'next_run_at'     => '2026-11-24 06:00:00', 'claim_token' => null, 'claimed_at' => null, 'last_run_at' => null, 'last_status' => null,
            'last_error_code' => null, 'consecutive_failures' => 0, 'version' => 1, 'created_at' => '2026-11-23 06:00:00', 'updated_at' => '2026-11-23 06:00:00',
        ]);
        $row = $this->storeOn($nulls)->find('x');
        self::assertNotNull($row);
        self::assertSame([[], []], [$row->parameters, $row->formats], 'NULL JSON reads as empty');
    }

    public function testALostConnectionInsideATransactionIsRethrown(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL84Platform());
        $connection->method('fetchAssociative')->willThrowException($this->lost());
        $this->expectException(ConnectionLost::class);
        $this->storeOn($connection)->find('x');
    }

    public function testAnUnknownManagerOrConnectionIsReported(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnection')->willReturn(new stdClass());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('names neither an ORM entity manager nor a DBAL connection');
        (new DbalSubscriptionStore($registry, new AfterCommitQueue()))->find('x');
    }

    public function testAfterCommitWorkWaitsForTheHostCommit(): void
    {
        $done = [];
        $this->connection->beginTransaction();
        $this->store->transactional(static fn (): int => 1, static function (int $result) use (&$done): void {
            $done[] = $result;
        });
        self::assertSame([], $done, 'joined the host transaction: held');
        $this->connection->commit();
        $this->store->transactional(static fn (): int => 2, static function (int $result) use (&$done): void {
            $done[] = $result;
        });
        self::assertSame([2], $done, 'without the middleware the held work is dropped at the boundary, not run');
    }

    private function storeOn(Connection $connection): DbalSubscriptionStore
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnection')->willReturn($connection);

        return new DbalSubscriptionStore($registry, new AfterCommitQueue(), 'default', 'nowo_', $this->logger);
    }

    private function lost(): ConnectionLost
    {
        return new ConnectionLost(new class('gone away', 'HY000', 2006) extends AbstractException {
        }, null);
    }

    private function subscription(string $id): Subscription
    {
        $now = new DateTimeImmutable('2026-11-23T06:00:00Z');

        return new Subscription($id, 'user', 'alice', 'orders', ['period_days' => 7, 'context' => null, 'extra' => []], 'daily', 1, 1, 7, 'UTC', ['pdf'], true, null, $now->modify('+1 day'), null, null, null, null, null, 0, 1, $now, $now);
    }

    private function runRow(string $id, DateTimeImmutable $slot): ReportRun
    {
        return new ReportRun($id, 's1', 'user', 'alice', $slot, ReportRun::RUNNING, [], 0, 0, null, 1, $slot, $slot);
    }
}
