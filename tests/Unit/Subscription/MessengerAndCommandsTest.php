<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Subscription;

use DateTimeImmutable;
use LogicException;
use Nowo\ReportKitBundle\Application\Run\RunResult;
use Nowo\ReportKitBundle\Application\Run\RunRetention;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Infrastructure\Console\DispatchDueCommand;
use Nowo\ReportKitBundle\Infrastructure\Console\PruneRunsCommand;
use Nowo\ReportKitBundle\Infrastructure\Messenger\DispatchDueReportsHandler;
use Nowo\ReportKitBundle\Infrastructure\Messenger\PruneReportRunsHandler;
use Nowo\ReportKitBundle\Infrastructure\Messenger\RunScheduledReportHandler;
use Nowo\ReportKitBundle\Infrastructure\Scheduler\ReportKitScheduleProvider;
use Nowo\ReportKitBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ReportKitBundle\Tests\Fixtures\SteppingClock;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Messenger handlers (REQ-MSG-001: attribute, success and failure paths), the commands, retention and the Scheduler
 * recipe.
 */
final class MessengerAndCommandsTest extends SubscriptionTestCase
{
    public function testHandlersCarryTheAttribute(): void
    {
        foreach ([DispatchDueReportsHandler::class, RunScheduledReportHandler::class, PruneReportRunsHandler::class] as $handler) {
            self::assertNotEmpty((new ReflectionClass($handler))->getAttributes(AsMessageHandler::class), $handler);
        }
    }

    public function testTheDispatchHandlerEnqueuesOneRunPerClaimAndTheRest(): void
    {
        $this->subscribe();
        $this->subscribe();
        $this->clock->modify('+1 day');
        $bus  = $this->bus();
        $pass = (new DispatchDueReportsHandler($this->dispatcher(batch: 1), $this->runner(), $bus))(new DispatchDueReportsMessage());
        self::assertCount(1, $pass->runs);
        self::assertCount(2, $bus->dispatched);
        self::assertInstanceOf(RunScheduledReportMessage::class, $bus->dispatched[0]);
        self::assertInstanceOf(DispatchDueReportsMessage::class, $bus->dispatched[1]);
        self::assertSame([], $this->mailer->sent, 'only enqueued');
    }

    public function testWithoutABusTheClaimedRunsRunInPlaceAndAFailureDoesNotStopTheOthers(): void
    {
        $this->subscribe();
        $this->subscribe();
        $this->clock->modify('+1 day');
        $this->mailer->failures = 1;
        $pass                   = (new DispatchDueReportsHandler($this->dispatcher(), $this->runner()))(new DispatchDueReportsMessage());
        self::assertCount(2, $pass->runs);
        self::assertCount(1, $this->mailer->sent);
    }

    public function testTheRunHandlerRethrowsForARetryAndMarksMisconfigurationUnrecoverable(): void
    {
        $this->subscribe();
        $this->clock->modify('+1 day');
        $run     = $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs[0];
        $handler = new RunScheduledReportHandler($this->runner());

        $this->mailer->failures = 1;
        try {
            $handler($run);
            self::fail('no retry');
        } catch (RuntimeException $e) {
            self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $e);
        }
        self::assertSame(RunStatus::Sent, $handler($run)->status, 'the retry sends');
        self::assertSame(RunResult::DUPLICATE, $handler($run)->code, 'a redelivery does not');

        $this->connection->beginTransaction();
        try {
            $handler($run);
            self::fail('ran inside a transaction');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertInstanceOf(LogicException::class, $e->getPrevious());
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testRetentionDeletesOldRunsInBatchesAndHandsTheRestBack(): void
    {
        foreach (['01933b1c-5d2e-7a3b-8c4d-00000000r001', '01933b1c-5d2e-7a3b-8c4d-00000000r002', '01933b1c-5d2e-7a3b-8c4d-00000000r003'] as $i => $id) {
            $this->connection->insert('nowo_report_run', ['id' => $id, 'subscription_id' => 's', 'owner_type' => 'user', 'owner_id' => 'alice', 'slot_at' => '2026-01-0' . ($i + 1) . ' 06:00:00', 'status' => 'sent', 'formats' => '[]', 'bytes' => 0, 'duration_ms' => 0, 'attempts' => 1, 'created_at' => '2026-01-0' . ($i + 1) . ' 06:00:00']);
        }
        $this->connection->insert('nowo_report_run', ['id' => '01933b1c-5d2e-7a3b-8c4d-00000000r009', 'subscription_id' => 's', 'owner_type' => 'user', 'owner_id' => 'alice', 'slot_at' => '2026-11-20 06:00:00', 'status' => 'sent', 'formats' => '[]', 'bytes' => 0, 'duration_ms' => 0, 'attempts' => 1, 'created_at' => '2026-11-20 06:00:00']);

        $timed = (new RunRetention($this->store, new SteppingClock(60, $this->clock->now()), 90, 1, 1))->prune(new PruneReportRunsMessage());
        self::assertSame(1, $timed->deleted);
        self::assertInstanceOf(PruneReportRunsMessage::class, $timed->remainder);

        $bus    = $this->bus();
        $report = (new PruneReportRunsHandler(new RunRetention($this->store, $this->clock, 90, 1, 50), $bus, new MemoryLogger()))($timed->remainder);
        self::assertSame(2, $report->deleted);
        self::assertNull($report->remainder);
        self::assertSame(['01933b1c-5d2e-7a3b-8c4d-00000000r009'], $this->connection->fetchFirstColumn('SELECT id FROM nowo_report_run'));

        // A bounded pass with a bus hands the rest to the bus; without one it loops.
        $this->connection->insert('nowo_report_run', ['id' => '01933b1c-5d2e-7a3b-8c4d-00000000r004', 'subscription_id' => 's', 'owner_type' => 'user', 'owner_id' => 'alice', 'slot_at' => '2026-01-04 06:00:00', 'status' => 'sent', 'formats' => '[]', 'bytes' => 0, 'duration_ms' => 0, 'attempts' => 1, 'created_at' => '2026-01-04 06:00:00']);
        $this->connection->insert('nowo_report_run', ['id' => '01933b1c-5d2e-7a3b-8c4d-00000000r005', 'subscription_id' => 's', 'owner_type' => 'user', 'owner_id' => 'alice', 'slot_at' => '2026-01-05 06:00:00', 'status' => 'sent', 'formats' => '[]', 'bytes' => 0, 'duration_ms' => 0, 'attempts' => 1, 'created_at' => '2026-01-05 06:00:00']);
        $slow = new RunRetention($this->store, new SteppingClock(60, $this->clock->now()), 90, 1, 1);
        (new PruneReportRunsHandler($slow, $bus))(new PruneReportRunsMessage());
        self::assertInstanceOf(PruneReportRunsMessage::class, $bus->dispatched[0] ?? null);
        self::assertSame(1, (new PruneReportRunsHandler($slow))(new PruneReportRunsMessage())->deleted, 'the second one, in a loop without a bus');
    }

    public function testTheDispatchCommandRunsInPlaceOrEnqueues(): void
    {
        $this->subscribe();
        $this->subscribe();
        $this->clock->modify('+1 day');
        $this->mailer->failures = 1;
        $tester                 = new CommandTester(new DispatchDueCommand($this->dispatcher(batch: 1), $this->runner()));
        self::assertSame(1, $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        self::assertStringContainsString('2 slot(s) claimed, run in this process, 1 failed.', $tester->getDisplay());
        self::assertStringContainsString(': sent', $tester->getDisplay());

        $this->clock->modify('+1 day');
        $bus    = $this->bus();
        $tester = new CommandTester(new DispatchDueCommand($this->dispatcher(), $this->runner(), $bus));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 slot(s) claimed, enqueued.', $tester->getDisplay());
        self::assertCount(2, $bus->dispatched);

        $this->clock->modify('+1 day');
        $tester = new CommandTester(new DispatchDueCommand($this->dispatcher(), $this->runner(), $bus));
        self::assertSame(0, $tester->execute(['--sync' => true]));
        self::assertStringContainsString('run in this process, 0 failed', $tester->getDisplay());
    }

    public function testThePruneCommand(): void
    {
        $tester = new CommandTester(new PruneRunsCommand(new RunRetention($this->store, $this->clock)));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0 run row(s) deleted.', $tester->getDisplay());
    }

    public function testTheScheduleRecipeOnlyEnqueues(): void
    {
        $messages = [];
        foreach ((new ReportKitScheduleProvider('*/15 * * * *'))->getSchedule()->getRecurringMessages() as $recurring) {
            self::assertInstanceOf(RecurringMessage::class, $recurring);
            foreach ($recurring->getProvider()->getMessages(new MessageContext('nowo_report_kit', $recurring->getId(), $recurring->getTrigger(), new DateTimeImmutable())) as $message) {
                $messages[] = $message::class . ' ' . $recurring->getTrigger();
            }
        }
        self::assertSame([DispatchDueReportsMessage::class . ' */15 * * * *', PruneReportRunsMessage::class . ' 17 3 * * *'], $messages);
    }

    private function bus(): MemoryBus
    {
        return new MemoryBus();
    }
}

/**
 * Bus double: keeps every dispatched message.
 */
final class MemoryBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return new Envelope($message);
    }
}
