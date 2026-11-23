<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Subscription;

use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Tests\Fixtures\SteppingClock;

/**
 * {@see \Nowo\ReportKitBundle\Application\Run\DueReportsDispatcher}: the atomic claim, its keyset, the stale claim,
 * the time budget and the off switch.
 */
final class DueReportsDispatcherTest extends SubscriptionTestCase
{
    public function testDueRowsAreClaimedOnceAndMoveToTheNextSlot(): void
    {
        $due    = $this->subscribe();
        $paused = $this->subscribe();
        $this->subscriptions()->setEnabled(self::owner(), $paused->id, false);
        $this->clock->modify('+1 day');

        $pass = $this->dispatcher()->claim(new DispatchDueReportsMessage());
        self::assertCount(1, $pass->runs);
        self::assertNull($pass->remainder);
        self::assertSame(1, $pass->scanned);
        self::assertSame($due->id->value, $pass->runs[0]->subscriptionId);
        self::assertSame('2026-11-24T06:00:00Z', $pass->runs[0]->slotAt);
        $row = $this->row($due->id->value);
        self::assertSame('2026-11-25 06:00:00', $row['next_run_at']);
        self::assertSame($pass->runs[0]->claimToken, $row['claim_token']);

        self::assertSame([], $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs, 'a second dispatcher wins nothing');
    }

    public function testMissedSlotsRunOnceAndAStaleClaimIsTakenAgain(): void
    {
        $view = $this->subscribe();
        $this->clock->modify('+5 days');
        self::assertCount(1, $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs);
        self::assertSame('2026-11-29 06:00:00', $this->row($view->id->value)['next_run_at'], 'next slot after now, not one per missed day');

        // The run never cleared its claim and the row is due again: blocked while the claim is fresh, taken once stale.
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET next_run_at = '2026-11-28 06:00:00', claimed_at = '2026-11-28 05:40:00'");
        self::assertSame([], $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs);
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET claimed_at = '2026-11-28 05:20:00'");
        self::assertCount(1, $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs);
    }

    public function testNothingIsClaimedWhileSchedulesAreOff(): void
    {
        $this->subscribe();
        $this->clock->modify('+1 day');
        self::assertSame([], $this->dispatcher(false)->claim(new DispatchDueReportsMessage())->runs);
    }

    public function testAFullBatchHandsTheRestBack(): void
    {
        $a = $this->subscribe();
        $b = $this->subscribe();
        $this->clock->modify('+1 day');
        $pass = $this->dispatcher(batch: 1)->claim(new DispatchDueReportsMessage());
        self::assertCount(1, $pass->runs);
        self::assertInstanceOf(DispatchDueReportsMessage::class, $pass->remainder);
        $first = $pass->runs[0]->subscriptionId;
        self::assertSame(min($a->id->value, $b->id->value), $first);
        $next = $this->dispatcher(batch: 1)->claim($pass->remainder);
        self::assertSame(max($a->id->value, $b->id->value), $next->runs[0]->subscriptionId);
    }

    public function testTheTimeBudgetStopsThePass(): void
    {
        $this->subscribe();
        $this->subscribe();
        $this->clock->modify('+1 day');
        $clock = new SteppingClock(60, $this->clock->now());
        $pass  = $this->dispatcher(clock: $clock, maxSeconds: 1)->claim(new DispatchDueReportsMessage());
        self::assertSame([], $pass->runs);
        self::assertInstanceOf(DispatchDueReportsMessage::class, $pass->remainder);
        self::assertNull($pass->remainder->after);
    }

    public function testADamagedScheduleIsSkipped(): void
    {
        $this->subscribe();
        $this->clock->modify('+1 day');
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET timezone = 'Mars/Olympus'");
        $pass = $this->dispatcher()->claim(new DispatchDueReportsMessage());
        self::assertSame([], $pass->runs);
        self::assertSame(1, $pass->scanned);
    }
}
