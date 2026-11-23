<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Subscription;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Event\ReportSubscriptionChangedEvent;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionChangedException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionLimitException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionId;

/**
 * {@see \Nowo\ReportKitBundle\Application\Subscription\Subscriptions}: create (cap, formats, first slot), owner-scoped
 * lookups with exact keys, pause / resume / delete with the optimistic version, events after commit.
 */
final class SubscriptionsTest extends SubscriptionTestCase
{
    public function testCreateStoresTheSubscriptionWithPdfFirstAndTheNextLocalSlot(): void
    {
        $view = $this->subscribe(formats: [ReportFormat::Csv, ReportFormat::Csv, ReportFormat::Xlsx]);

        self::assertSame([ReportFormat::Pdf, ReportFormat::Csv, ReportFormat::Xlsx], $view->formats);
        self::assertTrue($view->enabled);
        self::assertNull($view->pauseReason);
        self::assertSame(1, $view->version);
        // 07:00 Madrid (CET, UTC+1) = 06:00 UTC; "now" is exactly 06:00 UTC, so the first slot is tomorrow.
        self::assertSame('2026-11-24T06:00:00+00:00', $view->nextRunAt->format(DATE_ATOM));
        $row = $this->row($view->id->value);
        self::assertSame('{"period_days":30,"context":{"type":"project","id":"a1"},"extra":{}}', $row['parameters']);
        self::assertSame('["pdf","csv","xlsx"]', $row['formats']);
        self::assertSame(['nowo_report_kit.subscription_changed:' . ReportSubscriptionChangedEvent::class], $this->eventNames());
        $event = $this->recorded[0][1];
        self::assertInstanceOf(ReportSubscriptionChangedEvent::class, $event);
        self::assertSame(ReportSubscriptionChangedEvent::CREATED, $event->change);
        self::assertTrue($event->subscriptionId->equals($view->id));
        self::assertSame('orders', $event->type);
    }

    public function testWithoutPdfTheChosenFormatsAreKept(): void
    {
        $this->formats = [ReportFormat::Csv];
        self::assertSame([ReportFormat::Csv], $this->subscribe(formats: [ReportFormat::Csv])->formats);
        self::assertSame([], $this->subscribe(formats: [])->formats, 'link-only mail');
    }

    public function testAFormatThatIsNotEnabledIsRefused(): void
    {
        $this->formats = [ReportFormat::Csv, ReportFormat::Pdf];
        $this->expectException(InvalidReportArgumentException::class);
        $this->subscribe(formats: [ReportFormat::Xlsx]);
    }

    public function testJsonIsNeverAMailFormat(): void
    {
        $this->formats[] = ReportFormat::Json;
        $this->expectException(InvalidReportArgumentException::class);
        $this->subscribe(formats: [ReportFormat::Json]);
    }

    public function testUnknownTypesAndFrequenciesAreRefused(): void
    {
        try {
            $this->subscribe(type: 'nope');
            self::fail('unknown type');
        } catch (UnknownReportTypeException) {
        }
        $this->expectException(InvalidReportArgumentException::class);
        $this->subscriptions(frequencies: ['daily'])->create(self::owner(), 'orders', new ReportParameters(7, null), new Schedule(Frequency::Weekly), []);
    }

    public function testTheCapPerOwnerHoldsAndOtherOwnersAreNotCounted(): void
    {
        $subscriptions = $this->subscriptions(2);
        foreach ([1, 2] as $i) {
            $subscriptions->create(self::owner(), 'orders', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
        }
        $subscriptions->create(self::owner('bob'), 'orders', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
        $subscriptions->create(self::owner('ALICE'), 'orders', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
        try {
            $subscriptions->create(self::owner(), 'orders', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
            self::fail('cap passed');
        } catch (SubscriptionLimitException $limit) {
            self::assertSame(2, $limit->limit);
        }
        self::assertCount(2, $subscriptions->ofOwner(self::owner()));
        self::assertSame(['ALICE', 'alice'], $this->connection->fetchFirstColumn("SELECT owner_id FROM nowo_report_owner_lock WHERE owner_id IN ('alice', 'ALICE') ORDER BY owner_id"));
    }

    public function testLookupsAreScopedToTheExactOwner(): void
    {
        $view = $this->subscribe();
        self::assertNotNull($this->subscriptions()->get(self::owner(), $view->id));
        foreach (['bob', 'ALICE', 'alicé'] as $other) {
            self::assertNull($this->subscriptions()->get(self::owner($other), $view->id), $other);
            self::assertFalse($this->subscriptions()->setEnabled(self::owner($other), $view->id, false), $other);
            self::assertFalse($this->subscriptions()->delete(self::owner($other), $view->id), $other);
            self::assertSame([], $this->subscriptions()->ofOwner(self::owner($other)), $other);
        }
        self::assertNull($this->subscriptions()->get(self::owner(), new SubscriptionId('00000000-0000-7000-8000-000000000000')));
        self::assertTrue((bool) $this->row($view->id->value)['enabled']);
    }

    public function testPauseAndResumeWithTheVersion(): void
    {
        $view          = $this->subscribe();
        $subscriptions = $this->subscriptions();
        $this->recorded = [];

        self::assertTrue($subscriptions->setEnabled(self::owner(), $view->id, false, 1));
        $paused = $subscriptions->get(self::owner(), $view->id);
        self::assertNotNull($paused);
        self::assertFalse($paused->enabled);
        self::assertSame(PauseReason::Owner, $paused->pauseReason);
        self::assertSame(2, $paused->version);

        try {
            $subscriptions->setEnabled(self::owner(), $view->id, true, 1);
            self::fail('stale version accepted');
        } catch (SubscriptionChangedException $changed) {
            self::assertSame(SubscriptionChangedException::CODE, $changed::CODE);
        }

        // Resume much later: the next slot is the first one after now, not the stale one.
        $this->clock->modify('+10 days');
        $this->connection->executeStatement('UPDATE nowo_report_subscription SET consecutive_failures = 2');
        self::assertTrue($subscriptions->setEnabled(self::owner(), $view->id, true, 2));
        $resumed = $subscriptions->get(self::owner(), $view->id);
        self::assertNotNull($resumed);
        self::assertTrue($resumed->enabled);
        self::assertNull($resumed->pauseReason);
        self::assertSame(0, $resumed->consecutiveFailures);
        self::assertSame('2026-12-04T06:00:00+00:00', $resumed->nextRunAt->format(DATE_ATOM));

        $changes = [];
        foreach ($this->recorded as [, $event]) {
            self::assertInstanceOf(ReportSubscriptionChangedEvent::class, $event);
            $changes[] = $event->change . ':' . ($event->reason->value ?? '-');
        }
        self::assertSame(['paused:owner', 'enabled:-'], $changes);
    }

    public function testDeleteWithTheVersion(): void
    {
        $view          = $this->subscribe();
        $subscriptions = $this->subscriptions();
        try {
            $subscriptions->delete(self::owner(), $view->id, 7);
            self::fail('stale version accepted');
        } catch (SubscriptionChangedException) {
        }
        self::assertTrue($subscriptions->delete(self::owner(), $view->id, 1));
        self::assertFalse($subscriptions->delete(self::owner(), $view->id));
        self::assertSame([], $subscriptions->ofOwner(self::owner()));
        self::assertSame(ReportSubscriptionChangedEvent::DELETED, $this->recorded[1][1]->change ?? null);
    }

    public function testDamagedRowsAreNeverListedNorReturned(): void
    {
        $good = $this->subscribe();
        $bad  = $this->subscribe(context: null);
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET parameters = '{\"period_days\":\"x\"}' WHERE id = ?", [$bad->id->value]);

        $ids = [];
        foreach ($this->subscriptions()->ofOwner(self::owner()) as $view) {
            $ids[] = $view->id->value;
        }
        self::assertSame([$good->id->value], $ids);
        self::assertNull($this->subscriptions()->get(self::owner(), $bad->id));
    }

    public function testResumingADamagedScheduleKeepsTheStoredSlot(): void
    {
        $view = $this->subscribe();
        $this->subscriptions()->setEnabled(self::owner(), $view->id, false);
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET frequency = 'hourly'");
        self::assertTrue($this->subscriptions()->setEnabled(self::owner(), $view->id, true));
        self::assertSame($view->nextRunAt->format('Y-m-d H:i:s'), $this->row($view->id->value)['next_run_at']);
    }

    public function testContextsAndExtrasRoundTrip(): void
    {
        $view = $this->subscriptions()->create(self::owner(), 'revenue', new ReportParameters(7, new ContextRef('project', 'a1'), ['channel' => 'shop', 'limit' => 5, 'gross' => false, 'since' => null]), new Schedule(Frequency::Monthly, 1, 28, 23, 'UTC'), []);
        $again = $this->subscriptions()->get(self::owner(), $view->id);
        self::assertNotNull($again);
        self::assertEquals($view->parameters, $again->parameters);
        self::assertEquals(new Schedule(Frequency::Monthly, 1, 28, 23, 'UTC'), $again->schedule);
        self::assertEquals(new DateTimeImmutable('2026-11-28T23:00:00+00:00'), $again->nextRunAt);
        self::assertNotNull($again->createdAt);
    }
}
