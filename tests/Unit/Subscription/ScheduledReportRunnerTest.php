<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Subscription;

use LogicException;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Application\Run\RunResult;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Application\Scope\NoContextScopeResolver;
use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\ContextChoice;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Event\ReportAccessCheckEvent;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\Event\ScheduledReportFailedEvent;
use Nowo\ReportKitBundle\Contract\Event\ScheduledReportPausedEvent;
use Nowo\ReportKitBundle\Contract\Event\ScheduledReportSentEvent;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\MailAttachment;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\PersonalReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\RevenueReportType;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\InMemoryUser;

use const DATE_ATOM;

/**
 * {@see ScheduledReportRunner}: one mail per slot, retries before and after the hand-over, the owner checked at run
 * time (inactive, other owner, lost access, lost context, zero contexts), auto-pause, the unready mailer, the
 * attachment cap, locale and claim hygiene.
 */
final class ScheduledReportRunnerTest extends SubscriptionTestCase
{
    public function testASlotIsSentOnceAsTheOwnerInTheOwnersLocale(): void
    {
        $view                 = $this->subscribe(formats: [ReportFormat::Csv]);
        $locales              = [];
        $this->mailer->onSend = function () use (&$locales): void {
            $locales[] = $this->translator->getLocale();
        };
        $this->directory->people['user:alice']['locale'] = 'de';
        $message                                         = $this->claim();

        $result = $this->runner()->run($message);
        self::assertSame(RunStatus::Sent, $result->status);
        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertSame('alice@example.test', $mail->to);
        self::assertSame('de', $mail->locale);
        self::assertSame('Alice', $mail->recipientName);
        self::assertSame(['de'], $locales, 'the mail is handed over in the owner locale');
        self::assertSame('en', $this->translator->getLocale(), 'and the locale is restored');
        self::assertSame(['report-pdf.pdf', 'report-csv.csv'], array_map(static fn (MailAttachment $a): string => $a->filename, $mail->attachments));
        self::assertFalse($mail->attachmentsDropped);
        self::assertSame('https://reports.example.test/reports/orders?period=30&context=project%3Aa1&seed=0', $mail->reportUrl);
        self::assertSame('https://reports.example.test/reports', $mail->subscriptionsUrl);
        self::assertSame('2026-11-24T06:00:00+00:00', $mail->slotAt->format(DATE_ATOM));
        self::assertTrue($mail->subscriptionId->equals($view->id));

        $row = $this->row($view->id->value);
        self::assertSame('sent', $row['last_status']);
        self::assertNull($row['claim_token'], 'the claim is released');
        self::assertSame(0, (int) $row['consecutive_failures']);
        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame(['sent', '["pdf","csv"]', 20, null], [$runs[0]['status'], $runs[0]['formats'], (int) $runs[0]['bytes'], $runs[0]['lease_at']]);

        $sent = $this->recorded[1][1];
        self::assertInstanceOf(ScheduledReportSentEvent::class, $sent);
        self::assertSame([ReportFormat::Pdf, ReportFormat::Csv], $sent->formats);
        self::assertSame(20, $sent->bytes);

        // A redelivery or a second claim of the same slot never sends again.
        self::assertSame(RunResult::DUPLICATE, $this->runner()->run($message)->code);
        self::assertCount(1, $this->mailer->sent);
    }

    public function testAFailureBeforeTheHandOverIsRetriedAndSendsOnce(): void
    {
        $view                   = $this->subscribe();
        $message                = $this->claim();
        $this->mailer->failures = 1;
        try {
            $this->runner()->run($message);
            self::fail('no rethrow');
        } catch (RuntimeException $e) {
            self::assertSame('SMTP down', $e->getMessage());
        }
        $row = $this->row($view->id->value);
        self::assertSame(['failed', 'mail_failed', 1], [$row['last_status'], $row['last_error_code'], (int) $row['consecutive_failures']]);
        self::assertSame(['failed', null], [$this->runs()[0]['status'], $this->runs()[0]['lease_at']]);
        $failed = $this->recorded[1][1];
        self::assertInstanceOf(ScheduledReportFailedEvent::class, $failed);
        self::assertSame(['mail_failed', 1], [$failed->errorCode, $failed->consecutiveFailures]);

        // Messenger retries the same message.
        self::assertSame(RunStatus::Sent, $this->runner()->run($message)->status);
        self::assertCount(1, $this->mailer->sent);
        self::assertSame(0, (int) $this->row($view->id->value)['consecutive_failures']);
        self::assertSame('2', (string) $this->runs()[0]['attempts']);
    }

    public function testAfterTheHandOverARetryNeverSendsAgain(): void
    {
        $this->subscribe();
        $message = $this->claim();
        // Simulates a crash after the mail left: the run row is `sending`.
        self::assertSame(RunStatus::Sent, $this->runner()->run($message)->status);
        $this->connection->executeStatement("UPDATE nowo_report_run SET status = 'sending'");
        self::assertSame(RunResult::DUPLICATE, $this->runner()->run($message)->code);
        self::assertCount(1, $this->mailer->sent);
    }

    public function testThreeFailuresInARowPauseWithoutRethrowing(): void
    {
        $view                   = $this->subscribe();
        $message                = $this->claim();
        $this->mailer->failures = 5;
        foreach ([1, 2] as $attempt) {
            try {
                $this->runner()->run($message);
                self::fail('no rethrow on attempt ' . $attempt);
            } catch (RuntimeException) {
            }
        }
        $result = $this->runner()->run($message);
        self::assertSame([RunStatus::Paused, PauseReason::RepeatedFailure, 'mail_failed'], [$result->status, $result->reason, $result->code]);
        $row = $this->row($view->id->value);
        self::assertSame([0, 'repeated_failure', 'paused', 3], [(int) $row['enabled'], $row['pause_reason'], $row['last_status'], (int) $row['consecutive_failures']]);
        self::assertSame('paused', $this->runs()[0]['status']);
        self::assertContains('nowo_report_kit.scheduled_report_paused:' . ScheduledReportPausedEvent::class, $this->eventNames());
        self::assertSame([], $this->mailer->sent);
    }

    public function testABuildOverALimitCountsAsAFailure(): void
    {
        $this->subscriptions(registry: $this->boomRegistry())->create(self::owner(), 'boom', new ReportParameters(30, null), new Schedule(Frequency::Daily, 1, 1, 7, 'Europe/Madrid'), []);
        $message = $this->claim();
        try {
            $this->runner($this->boomRegistry())->run($message);
            self::fail('no rethrow');
        } catch (ReportLimitExceededException) {
        }
        self::assertSame('limit_exceeded', $this->runs()[0]['error_code']);
    }

    public function testAnInactiveGoneOrMismatchedOwnerPauses(): void
    {
        $cases = [
            'inactive' => static function (array &$person): void {
                $person['active'] = false;
            },
            'no e-mail' => static function (array &$person): void {
                $person['email'] = 'not an address';
            },
            'other owner' => static function (array &$person): void {
                $person['user'] = new InMemoryUser('ALICE', null);
            },
            'gone' => null,
        ];
        foreach ($cases as $label => $change) {
            $this->setUp();
            $view = $this->subscribe();
            if ($change === null) {
                unset($this->directory->people['user:alice']);
            } else {
                $change($this->directory->people['user:alice']);
            }
            $result = $this->runner()->run($this->claim());
            self::assertSame([RunStatus::Paused, PauseReason::OwnerInactive], [$result->status, $result->reason], $label);
            self::assertSame('owner_inactive', $this->row($view->id->value)['pause_reason'], $label);
            self::assertSame([], $this->mailer->sent, $label);
        }
    }

    public function testLostAccessToTheTypeOrTheContextPauses(): void
    {
        // The subscribed context is no longer among alice's contexts: never widened to "all accessible".
        $view    = $this->subscribe(context: new ContextRef('project', 'a2'));
        $message = $this->claim();
        $scope   = new class implements ReportScopeResolverInterface {
            public function accessibleContexts(OwnerRef $owner, object $user, string $type): array
            {
                return [new ContextChoice(new ContextRef('project', 'a1'), 'Alpha')];
            }
        };
        $result = $this->runner(scope: $scope)->run($message);
        self::assertSame(PauseReason::NoAccess, $result->reason);
        self::assertSame('no_access', $this->row($view->id->value)['pause_reason']);

        // The type's permission is gone (revenue is granted on a1 only; bob has b1).
        $this->setUp();
        $revenue = $this->subscriptions()->create(self::owner(), 'revenue', new ReportParameters(7, new ContextRef('project', 'a1')), new Schedule(Frequency::Daily, 1, 1, 7, 'Europe/Madrid'), []);
        $message = $this->claim();
        $denied  = new class implements ReportAccessCheckerInterface {
            public function allows(OwnerRef $owner, object $user, string $type, array $contexts): array
            {
                return [];
            }

            public function allowsWithoutContext(OwnerRef $owner, object $user, string $type): bool
            {
                return false;
            }
        };
        self::assertSame(PauseReason::NoAccess, $this->runner(access: $denied)->run($message)->reason);
        self::assertSame([], $this->mailer->sent);
        self::assertSame('revenue', $revenue->type);
    }

    public function testZeroContextsOnARealScopeResolverFailClosedUnlessGranted(): void
    {
        // carol has no context at all (audit ZY-M1): a context-free subscription of a context type is refused at run time.
        $this->directory->add('carol');
        $this->subscribe(context: null, owner: 'carol');
        $message = $this->claim();
        self::assertSame(PauseReason::NoAccess, $this->runner()->run($message)->reason);

        // An explicit grant opens it; the build knows it was context-free.
        $this->setUp();
        $this->directory->add('carol');
        $this->subscribe(context: null, owner: 'carol');
        $message   = $this->claim();
        $events    = new EventDispatcher();
        $scheduled = [];
        $events->addListener(ReportKitEvents::REPORT_ACCESS_CHECK, static function (ReportAccessCheckEvent $event) use (&$scheduled): void {
            $scheduled[] = $event->scheduled;
            $event->grant();
        });
        self::assertSame(RunStatus::Sent, $this->runner(builderEvents: $events)->run($message)->status);
        self::assertSame([true], $scheduled, 'the access check runs again, marked scheduled');
    }

    public function testARemovedTypePauses(): void
    {
        $view    = $this->subscribe();
        $message = $this->claim();
        $result  = $this->runner($this->registry(disabled: ['orders']))->run($message);
        self::assertSame(PauseReason::TypeRemoved, $result->reason);
        self::assertSame('type_removed', $this->row($view->id->value)['pause_reason']);
    }

    public function testAnUnreadyMailerSkipsAndKeepsTheSchedule(): void
    {
        $view                = $this->subscribe();
        $message             = $this->claim();
        $this->mailer->ready = false;
        $result              = $this->runner()->run($message);
        self::assertSame([RunStatus::Skipped, 'mail_not_ready', null], [$result->status, $result->code, $result->reason]);
        $row = $this->row($view->id->value);
        self::assertSame([1, null, 'skipped', 'mail_not_ready'], [(int) $row['enabled'], $row['pause_reason'], $row['last_status'], $row['last_error_code']]);
        self::assertSame('2026-11-25 06:00:00', $row['next_run_at']);
    }

    public function testASubscriptionPausedOrDeletedAfterTheClaimIsNotSent(): void
    {
        $view    = $this->subscribe();
        $message = $this->claim();
        $this->subscriptions()->setEnabled(self::owner(), $view->id, false);
        $result = $this->runner()->run($message);
        self::assertSame([RunStatus::Skipped, 'paused'], [$result->status, $result->code]);
        self::assertSame('owner', $this->row($view->id->value)['pause_reason'], 'the owner pause is kept');

        $other   = $this->subscribe();
        $message = $this->claim();
        $this->subscriptions()->delete(self::owner(), $other->id);
        self::assertSame(RunResult::GONE, $this->runner()->run($message)->code);
        self::assertSame([], $this->mailer->sent);
    }

    public function testTooLargeFilesAreDroppedAndTheMailCarriesTheLink(): void
    {
        $this->subscribe(formats: [ReportFormat::Csv, ReportFormat::Xlsx]);
        $message = $this->claim();
        self::assertSame(RunStatus::Sent, $this->runner(maxBytes: 25)->run($message)->status);
        $mail = $this->mailer->sent[0];
        self::assertSame([], $mail->attachments);
        self::assertTrue($mail->attachmentsDropped);
        self::assertSame(['[]', 0], [$this->runs()[0]['formats'], (int) $this->runs()[0]['bytes']]);
    }

    public function testFormatsSwitchedOffSinceAreLeftOut(): void
    {
        $this->subscribe(formats: [ReportFormat::Csv, ReportFormat::Xlsx]);
        $message       = $this->claim();
        $this->formats = [ReportFormat::Pdf];
        $this->runner()->run($message);
        self::assertSame(['report-pdf.pdf'], array_map(static fn (MailAttachment $a): string => $a->filename, $this->mailer->sent[0]->attachments));
    }

    public function testDamagedMessagesAndRowsFailClosed(): void
    {
        self::assertSame(RunResult::GONE, $this->runner()->run(new RunScheduledReportMessage('nope', '2026-11-24T06:00:00Z', 't'))->code);
        self::assertSame(RunResult::GONE, $this->runner()->run(new RunScheduledReportMessage('01933b1c-5d2e-7a3b-8c4d-00000000b002', '2026-11-24 06:00', 't'))->code);
        self::assertSame(RunResult::GONE, $this->runner()->run(new RunScheduledReportMessage('01933b1c-5d2e-7a3b-8c4d-00000000b002', '2026-11-24T06:00:00Z', 't'))->code);

        $view    = $this->subscribe();
        $message = $this->claim();
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET owner_id = ' alice'");
        self::assertSame(PauseReason::OwnerInactive, $this->runner()->run($message)->reason);
        self::assertSame(0, (int) $this->row($view->id->value)['enabled']);
    }

    public function testItRefusesToRunInsideATransaction(): void
    {
        $this->subscribe();
        $message = $this->claim();
        $this->connection->beginTransaction();
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('doctrine_transaction');
            $this->runner()->run($message);
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testAStaleLeaseIsTakenOverAndAFreshOneIsNot(): void
    {
        $this->subscribe();
        $message = $this->claim();
        $this->connection->insert('nowo_report_run', [
            'id'       => '01933b1c-5d2e-7a3b-8c4d-00000000c001', 'subscription_id' => $message->subscriptionId, 'owner_type' => 'user', 'owner_id' => 'alice',
            'slot_at'  => '2026-11-24 06:00:00', 'status' => 'running', 'formats' => '[]', 'bytes' => 0, 'duration_ms' => 0, 'error_code' => null,
            'attempts' => 1, 'lease_at' => $this->clock->now()->format('Y-m-d H:i:s'), 'created_at' => '2026-11-24 06:00:00',
        ]);
        self::assertSame(RunResult::DUPLICATE, $this->runner()->run($message)->code, 'a worker holds the lease');
        $this->connection->executeStatement("UPDATE nowo_report_run SET lease_at = '2026-11-24 05:00:00'");
        self::assertSame(RunStatus::Sent, $this->runner()->run($message)->status, 'a stale lease is taken over');
    }

    public function testAClaimTakenOverByAnotherDispatcherIsKept(): void
    {
        $view    = $this->subscribe();
        $message = $this->claim();
        $this->connection->executeStatement("UPDATE nowo_report_subscription SET claim_token = 'other-token'");
        $this->runner()->run($message);
        self::assertSame('other-token', $this->row($view->id->value)['claim_token']);
    }

    public function testAPersonalTypeRunsWithoutContexts(): void
    {
        $this->subscribe(type: 'personal', context: null);
        $message = $this->claim();
        self::assertSame(RunStatus::Sent, $this->runner(scope: new NoContextScopeResolver())->run($message)->status);
        self::assertSame('https://reports.example.test/reports/personal?period=30', $this->mailer->sent[0]->reportUrl);
    }

    /**
     * Moves the clock past the first slot and claims it.
     */
    private function claim(): RunScheduledReportMessage
    {
        $this->clock->modify('+1 day');
        $pass = $this->dispatcher()->claim(new DispatchDueReportsMessage());
        self::assertNotEmpty($pass->runs);

        return $pass->runs[0];
    }

    private function boomRegistry(): ReportTypeRegistry
    {
        $boom = new class($this->translator) extends AbstractReportType {
            public function key(): string
            {
                return 'boom';
            }

            public function build(ReportRequest $request): Report
            {
                throw new ReportLimitExceededException(ReportLimitExceededException::SCOPE_CONTEXTS, 1);
            }
        };

        return $this->registry([new OrdersReportType($this->translator), new RevenueReportType($this->translator), new PersonalReportType($this->translator), $boom]);
    }
}
