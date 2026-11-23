<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\ReportKitBundle\Application\Run\RunResult;
use Nowo\ReportKitBundle\Application\Run\RunRetention;
use Nowo\ReportKitBundle\Contract\Exception\ReportKitConflictException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionChangedException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionLimitException;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\Domain\SubscriptionStoreInterface;
use Nowo\ReportKitBundle\Infrastructure\Http\ReportKitConflictListener;
use Nowo\ReportKitBundle\Tests\Integration\Kernel\Host;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function base64_encode;
use function dirname;
use function fclose;
use function fgets;
use function fwrite;
use function hrtime;
use function is_resource;
use function json_encode;
use function proc_close;
use function proc_open;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const STDERR;

/**
 * Two-connection race tests (REQ-RUNTIME-003): a second PHP process (`tests/Fixtures/hold-lock.php`) holds real row
 * locks on the same database (SQLite file, MySQL 8.4, MariaDB 11.4, PostgreSQL 16 in the Databases job) while this
 * process runs the bundle's services:
 *
 * - two dispatchers claim one due row: exactly one claim (the other waits, then its conditional UPDATE matches nothing);
 * - a slot whose run row another worker is inserting is never run twice (the unique key waits, then refuses);
 * - the per-owner cap holds under a concurrent create (the owner lock row serialises them);
 * - a lock wait timeout on create answers `CONFLICT_001` (409 + Retry-After + X-Conflict-Code), writes nothing and
 *   leaves the connection usable; a timeout in a later retention batch answers `CONFLICT_002`;
 * - a pause decided on a stale version is refused (optimistic version).
 *
 * Every session has a bounded lock wait, so a regression fails fast instead of hanging.
 */
abstract class ConcurrencyTestCase extends SubscriptionFunctionalTestCase
{
    /** @var array{0: resource, 1: resource}|null child stdin / stdout */
    private ?array $pipes = null;

    /** @var resource|null */
    private $process;

    protected function setUp(): void
    {
        parent::setUp();
        $platform = $this->connection()->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->connection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 3');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->connection()->executeStatement("SET lock_timeout = '3s'");
        } elseif ($platform instanceof SQLitePlatform) {
            $this->connection()->executeStatement('PRAGMA busy_timeout = 3000');
        }
    }

    protected function tearDown(): void
    {
        $this->releaseChild();
        parent::tearDown();
    }

    public function testTwoDispatchersClaimADueRowOnce(): void
    {
        $view = $this->subscribe('alice');
        $this->clock()->modify('+1 day');
        $now = new DateTimeImmutable(Host::NOW . ' +1 day', new DateTimeZone('UTC'));
        // Session B: another dispatcher's claim of the same row, not committed yet.
        $this->startChild([[$this->table('report_subscription'), ['claim_token' => 'other-dispatcher', 'claimed_at' => $this->time($now), 'next_run_at' => $this->time($now->modify('+1 day'))], [], ['id' => $view->id->value]]], 1000);
        $started = hrtime(true);
        $runs    = $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs;
        self::assertGreaterThan(500_000_000, hrtime(true) - $started, 'the claim waited for the other one');
        self::assertSame([], $runs, 'the row moved on: nothing claimed twice');
        self::assertSame('other-dispatcher', $this->connection()->fetchOne('SELECT claim_token FROM ' . $this->table('report_subscription') . ' WHERE id = ?', [$view->id->value]));
    }

    public function testASlotAnotherWorkerIsStartingIsNotRunTwice(): void
    {
        $view = $this->subscribe('alice');
        $run  = $this->claimOne();
        $now  = $this->clock()->now();
        $this->startChild([[$this->table('report_run'), [
            'id'       => '01933b1c-5d2e-7a3b-8c4d-00000000c001', 'subscription_id' => $view->id->value, 'owner_type' => $view->owner->type, 'owner_id' => $view->owner->id,
            'slot_at'  => $this->time(new DateTimeImmutable($run->slotAt)), 'status' => 'running', 'formats' => '[]', 'bytes' => 0, 'duration_ms' => 0, 'error_code' => null,
            'attempts' => 1, 'lease_at' => $this->time($now), 'created_at' => $this->time($now),
        ], ['bytes' => Types::INTEGER, 'duration_ms' => Types::INTEGER, 'attempts' => Types::INTEGER], null]], 1000);
        self::assertSame(RunResult::DUPLICATE, $this->runner()->run($run)->code);
        self::assertSame([], $this->mailer()->sent);
    }

    public function testTheCapHoldsWhileAConcurrentCreateHoldsTheOwnerLock(): void
    {
        $this->subscribe('alice');
        $second = $this->subscribe('alice');
        $owner  = $this->host()->owner('alice');
        $row    = $this->connection()->fetchAssociative('SELECT * FROM ' . $this->table('report_subscription') . ' WHERE id = ?', [$second->id->value]);
        self::assertIsArray($row);
        $row['id'] = '01933b1c-5d2e-7a3b-8c4d-00000000d003';
        unset($row['enabled']);
        // Session B: a concurrent create that took alice's lock row and inserted her third subscription, not committed.
        $this->startChild([
            [$this->table('report_owner_lock'), ['version' => 99], [], ['owner_type' => $owner->type, 'owner_id' => $owner->id]],
            [$this->table('report_subscription'), $row + ['enabled' => true], ['enabled' => Types::BOOLEAN], null],
        ], 1000);
        $started = hrtime(true);
        try {
            $this->subscribe('alice');
            self::fail('the fourth subscription passed the cap of 3');
        } catch (SubscriptionLimitException) {
        }
        self::assertGreaterThan(500_000_000, hrtime(true) - $started, 'the create waited for the concurrent lock holder');
        self::assertCount(3, $this->service(SubscriptionsInterface::class)->ofOwner($owner));
    }

    public function testALockWaitTimeoutOnCreateAnswersConflict001(): void
    {
        $this->subscribe('alice');
        $owner = $this->host()->owner('alice');
        $this->startChild([[$this->table('report_owner_lock'), ['version' => 99], [], ['owner_type' => $owner->type, 'owner_id' => $owner->id]]], null);
        try {
            $this->subscribe('alice');
            self::fail('no conflict');
        } catch (ReportKitConflictException $conflict) {
            self::assertSame(ConflictCode::RetrySafe, $conflict->conflictCode);
            self::assertSame(3, $conflict->conflict()->attempts, 'replayed before giving up');
            $answer = $this->answer($conflict);
            self::assertSame(409, $answer->getStatusCode());
            self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $answer->getHeaders());
        }
        self::assertCount(1, $this->service(SubscriptionsInterface::class)->ofOwner($owner), 'nothing of the failed create was written');
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT 1'), 'the connection is still usable');
        $this->releaseChild();
        $this->subscribe('alice');
    }

    public function testALockWaitTimeoutInALaterRetentionBatchAnswersConflict002(): void
    {
        $old = new DateTimeImmutable('2026-01-01 06:00:00', new DateTimeZone('UTC'));
        foreach (['01933b1c-5d2e-7a3b-8c4d-00000000e001', '01933b1c-5d2e-7a3b-8c4d-00000000e002'] as $i => $id) {
            $this->connection()->insert($this->table('report_run'), [
                'id'      => $id, 'subscription_id' => 's', 'owner_type' => 'user', 'owner_id' => 'alice', 'slot_at' => $this->time($old->modify('+' . $i . ' days')), 'status' => 'sent',
                'formats' => '[]', 'bytes' => 0, 'duration_ms' => 0, 'attempts' => 1, 'created_at' => $this->time($old),
            ], ['bytes' => Types::INTEGER, 'duration_ms' => Types::INTEGER, 'attempts' => Types::INTEGER]);
        }
        $this->startChild([[$this->table('report_run'), ['status' => 'skipped'], [], ['id' => '01933b1c-5d2e-7a3b-8c4d-00000000e002']]], null);
        try {
            (new RunRetention($this->store(), $this->clock(), 90, 1, 50))->prune(new PruneReportRunsMessage());
            self::fail('no conflict');
        } catch (ReportKitConflictException $conflict) {
            if ($this->connection()->getDatabasePlatform() instanceof SQLitePlatform) {
                // SQLite locks the whole database: the first batch already waits, nothing was committed.
                self::assertSame(ConflictCode::RetrySafe, $conflict->conflictCode);
                $this->releaseChild();
                self::assertCount(2, $this->connection()->fetchFirstColumn('SELECT id FROM ' . $this->table('report_run')));

                return;
            }
            self::assertSame(ConflictCode::Committed, $conflict->conflictCode);
            self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $this->answer($conflict)->getHeaders(), 'no Retry-After: read again');
        }
        $this->releaseChild();
        self::assertSame(['01933b1c-5d2e-7a3b-8c4d-00000000e002'], $this->connection()->fetchFirstColumn('SELECT id FROM ' . $this->table('report_run')), 'the first batch stays deleted');
    }

    public function testAPauseDecidedOnAStaleVersionIsRefused(): void
    {
        $view          = $this->subscribe('alice');
        $subscriptions = $this->service(SubscriptionsInterface::class);
        $this->runner()->run($this->claimOne());
        $this->expectException(SubscriptionChangedException::class);
        $subscriptions->setEnabled($this->host()->owner('alice'), $view->id, false, $view->version);
    }

    private function answer(Throwable $failure): HttpExceptionInterface
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/reports/orders/schedule', 'POST'), HttpKernelInterface::MAIN_REQUEST, $failure);
        (new ReportKitConflictListener(new ConflictResponsePolicy()))->onKernelException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpExceptionInterface::class, $answer);

        return $answer;
    }

    /**
     * Starts the second session and waits until it holds its locks.
     *
     * @param list<array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: array<string, mixed>|null}> $writes
     */
    private function startChild(array $writes, ?int $holdMs): void
    {
        $job = base64_encode(json_encode(['params' => $this->connection()->getParams(), 'writes' => $writes, 'hold_ms' => $holdMs], JSON_THROW_ON_ERROR));
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (test helper: the child bounds every lock wait to 10 s and is released in tearDown)
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/hold-lock.php', $job], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        self::assertIsResource($process);
        $this->process = $process;
        $this->pipes   = [$pipes[0], $pipes[1]];
        self::assertSame('locked', trim((string) fgets($pipes[1])), 'the second session holds its locks');
    }

    private function releaseChild(): void
    {
        if ($this->pipes === null || !is_resource($this->process)) {
            return;
        }
        fwrite($this->pipes[0], "go\n");
        fclose($this->pipes[0]);
        $done = trim((string) fgets($this->pipes[1]));
        fclose($this->pipes[1]);
        $status        = proc_close($this->process);
        $this->pipes   = null;
        $this->process = null;
        self::assertSame('done', $done);
        self::assertSame(0, $status, 'the second session committed');
    }

    private function store(): SubscriptionStoreInterface
    {
        $store = $this->service('nowo_report_kit.subscription_store');
        self::assertInstanceOf(SubscriptionStoreInterface::class, $store);

        return $store;
    }

    private function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format($this->connection()->getDatabasePlatform()->getDateTimeFormatString());
    }

    private function table(string $name): string
    {
        return $this->host()->tablePrefix() . $name;
    }
}
