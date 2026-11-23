<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Subscription;

use Closure;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Application\Run\RunResult;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Application\Scope\NoContextScopeResolver;
use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\Event\ReportAccessCheckEvent;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionView;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\HookedStore;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Audit ZAD-L1 / ZAD-I1: only the attempt that holds a run slot may move or close it (per-attempt lease token), a
 * slot that reached `sending` is never taken again, and a pause or delete committed while the report is built stops
 * the mail.
 */
final class LeaseTest extends SubscriptionTestCase
{
    /** Runs while attempt A builds: makes A's lease stale and lets a redelivery B run the slot to the end. */
    private ?Closure $during = null;

    public function testAStaleAttemptThatFailsLateNeverCausesASecondMail(): void
    {
        $view         = $this->probe();
        $message      = $this->claim();
        $this->during = function () use ($message): never {
            $this->during = null;
            $this->connection->executeStatement("UPDATE nowo_report_run SET lease_at = '2026-11-20 00:00:00'");
            self::assertSame(RunStatus::Sent, $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message)->status, 'B sends');

            throw new RuntimeException('A fails late');
        };
        $result = $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message);
        self::assertSame(RunResult::DUPLICATE, $result->code, 'A records nothing and is not retried');
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('sent', $this->runs()[0]['status'], 'B\'s sent row is never moved back to failed');
        self::assertSame(0, (int) $this->row($view->id->value)['consecutive_failures'], 'no spurious failure');

        // A Messenger redelivery of A afterwards cannot retake a slot that was sent.
        $this->during = null;
        self::assertSame(RunResult::DUPLICATE, $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message)->code);
        self::assertCount(1, $this->mailer->sent);
    }

    public function testAStaleAttemptThatReachesTheHandOverStops(): void
    {
        $this->probe();
        $message      = $this->claim();
        $this->during = function () use ($message): void {
            $this->connection->executeStatement("UPDATE nowo_report_run SET lease_at = '2026-11-20 00:00:00'");
            $this->during = null;
            $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message);
        };
        self::assertSame(RunResult::DUPLICATE, $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message)->code);
        self::assertCount(1, $this->mailer->sent);
    }

    public function testAStaleAttemptThatPausesLateRecordsNothing(): void
    {
        $view    = $this->probe();
        $message = $this->claim();
        $events  = new EventDispatcher();
        $nested  = false;
        $events->addListener(ReportKitEvents::REPORT_ACCESS_CHECK, function (ReportAccessCheckEvent $event) use ($message, &$nested): void {
            if ($nested) {
                return;
            }
            $nested = true;
            $this->connection->executeStatement("UPDATE nowo_report_run SET lease_at = '2026-11-20 00:00:00'");
            $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message);
            $event->deny();
        });
        $result = $this->runner($this->probeRegistry(), new NoContextScopeResolver(), builderEvents: $events)->run($message);
        self::assertSame(RunResult::DUPLICATE, $result->code);
        self::assertSame(1, (int) $this->row($view->id->value)['enabled'], 'the superseded attempt does not pause the subscription');
        self::assertCount(1, $this->mailer->sent);
    }

    public function testAPauseOrDeleteDuringTheBuildStopsTheMail(): void
    {
        $view         = $this->probe();
        $message      = $this->claim();
        $this->during = function () use ($view): void {
            $this->subscriptions(registry: $this->probeRegistry())->setEnabled(self::owner(), $view->id, false);
        };
        $result = $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message);
        self::assertSame([RunStatus::Skipped, ScheduledReportRunner::PAUSED_MEANTIME], [$result->status, $result->code]);

        $other        = $this->probe();
        $message      = $this->claim();
        $this->during = function () use ($other): void {
            $this->subscriptions(registry: $this->probeRegistry())->delete(self::owner(), $other->id);
        };
        $result = $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message);
        self::assertSame([RunStatus::Skipped, ScheduledReportRunner::SUBSCRIPTION_DELETED], [$result->status, $result->code]);
        self::assertSame([], $this->mailer->sent);
        self::assertSame(['skipped', 'skipped'], array_column($this->runs(), 'status'));
    }

    public function testADeleteWhileTheSlotIsTakenStopsBeforeTheBuild(): void
    {
        $view             = $this->probe();
        $message          = $this->claim();
        $store            = new HookedStore($this->store);
        $this->store      = $store;
        $store->afterTake = function () use ($view): void {
            $this->connection->executeStatement('DELETE FROM nowo_report_subscription WHERE id = ?', [$view->id->value]);
        };
        $result = $this->runner($this->probeRegistry(), new NoContextScopeResolver())->run($message);
        self::assertSame([RunStatus::Skipped, ScheduledReportRunner::SUBSCRIPTION_DELETED], [$result->status, $result->code]);
        self::assertSame('skipped', $this->runs()[0]['status']);
        self::assertSame([], $this->mailer->sent);
    }

    private function probe(): SubscriptionView
    {
        return $this->subscriptions(registry: $this->probeRegistry())->create(self::owner(), 'probe', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
    }

    private function claim(): RunScheduledReportMessage
    {
        $this->clock->modify('+1 day');

        return $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs[0];
    }

    private function probeRegistry(): ReportTypeRegistry
    {
        $hook = fn (): ?Closure => $this->during;

        return $this->registry([new class($this->translator, $hook) extends AbstractReportType {
            public function __construct(TranslatorInterface $translator, private readonly Closure $hook)
            {
                parent::__construct($translator);
            }

            public function key(): string
            {
                return 'probe';
            }

            public function parameters(): ParameterSchema
            {
                return new ParameterSchema(context: false);
            }

            public function build(ReportRequest $request): Report
            {
                $during = ($this->hook)();
                if ($during instanceof Closure) {
                    $during();
                }

                return $this->report($request, []);
            }
        }]);
    }
}
