<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Nowo\ReportKitBundle\Application\Run\DueReportsDispatcher;
use Nowo\ReportKitBundle\Application\Run\RunResult;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Event\ReportSubscriptionChangedEvent;
use Nowo\ReportKitBundle\Contract\Event\ScheduledReportSentEvent;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionLimitException;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\Contract\SubscriptionView;
use Nowo\ReportKitBundle\Tests\Fixtures\FlushSpy;
use Nowo\ReportKitBundle\Tests\Fixtures\RecordingListener;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\RecordingMailer;
use Nowo\ReportKitBundle\Tests\Integration\Kernel\Host;
use Nowo\ReportKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ReportKitBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The S3 functional suite, run on both host kinds and on every database of the CI matrix: create (cap, exact owners),
 * claim and run as the owner through the container services, the mail to the owner's current address, a paused
 * owner, events after the commit of a host transaction, and no host flush.
 */
abstract class SubscriptionFunctionalTestCase extends KernelTestCase
{
    protected TestKernel $kernel;

    abstract protected function host(): Host;

    protected function setUp(): void
    {
        $host         = $this->host();
        $url          = Host::databaseUrl();
        $configure    = $host->configure($url);
        $this->kernel = $this->bootKernel($host->bundleConfig(), $url, static function (ContainerConfigurator $c) use ($configure): void {
            $configure($c);
            $c->services()->set(FlushSpy::class)->public()->tag('doctrine.event_listener', ['event' => 'onFlush']);
            $c->services()->set(RecordingListener::class)->public()
                ->tag('kernel.event_listener', ['event' => 'nowo_report_kit.subscription_changed', 'method' => 'onEvent'])
                ->tag('kernel.event_listener', ['event' => 'nowo_report_kit.scheduled_report_sent', 'method' => 'onEvent'])
                ->tag('kernel.event_listener', ['event' => 'nowo_report_kit.scheduled_report_paused', 'method' => 'onEvent']);
        });
        $host->resetSchema($this->kernel);
        foreach (['alice', 'bob'] as $id) {
            $host->createUser($this->kernel, $id);
        }
    }

    public function testCreateClaimRunAndSendAsTheOwner(): void
    {
        $flushes = $this->service(FlushSpy::class)->flushes;
        $view    = $this->subscribe('alice');
        $this->clock()->modify('+1 day');
        $runs = $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs;
        self::assertCount(1, $runs);

        $result = $this->runner()->run($runs[0]);
        self::assertSame(RunStatus::Sent, $result->status);
        self::assertSame(RunResult::DUPLICATE, $this->runner()->run($runs[0])->code);
        $mails = $this->mailer()->sent;
        self::assertCount(1, $mails);
        self::assertSame('alice@example.test', $mails[0]->to);
        self::assertTrue($mails[0]->owner->equals($this->host()->owner('alice')));
        self::assertSame($this->host()->kind === Host::DDD ? 'es' : 'en', $mails[0]->locale);
        self::assertSame($flushes, $this->service(FlushSpy::class)->flushes, 'no entity manager was flushed by the bundle');

        $classes = [];
        foreach ($this->service(RecordingListener::class)->events as $event) {
            $classes[] = $event::class;
        }
        self::assertSame([ReportSubscriptionChangedEvent::class, ScheduledReportSentEvent::class], $classes);
        self::assertSame('sent', $this->connection()->fetchOne('SELECT last_status FROM ' . $this->host()->tablePrefix() . 'report_subscription WHERE id = ?', [$view->id->value]));
    }

    public function testTheCapAndTheOwnersAreExactOnThisDatabase(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->subscribe('usera');
        }
        try {
            $this->subscribe('usera');
            self::fail('cap passed');
        } catch (SubscriptionLimitException) {
        }
        $other         = $this->subscribe('UserA');
        $subscriptions = $this->service(SubscriptionsInterface::class);
        self::assertCount(3, $subscriptions->ofOwner($this->host()->owner('usera')));
        self::assertCount(1, $subscriptions->ofOwner($this->host()->owner('UserA')));
        self::assertNull($subscriptions->get($this->host()->owner('usera'), $other->id), 'a look-alike owner never reaches the row');
        self::assertFalse($subscriptions->delete($this->host()->owner('usera'), $other->id));
        // SQL level: the binary collation keeps the two owners apart (MySQL / MariaDB NO PAD, exact elsewhere).
        self::assertSame(3, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->host()->tablePrefix() . 'report_subscription WHERE owner_id = ?', ['usera']));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->host()->tablePrefix() . 'report_subscription WHERE owner_id = ?', ['usera ']));
    }

    public function testForgetOwnerErasesOnlyThatOwnerOnThisDatabase(): void
    {
        $this->subscribe('usera');
        $this->runner()->run($this->claimOne());
        $this->subscribe('UserA');
        $this->subscribe('alice');
        $subscriptions = $this->service(SubscriptionsInterface::class);

        self::assertSame(['subscriptions' => 1, 'runs' => 1], $subscriptions->forgetOwner($this->host()->owner('usera')));
        self::assertSame([], $subscriptions->ofOwner($this->host()->owner('usera')));
        self::assertCount(1, $subscriptions->ofOwner($this->host()->owner('UserA')), 'the look-alike owner keeps its row');
        self::assertCount(1, $subscriptions->ofOwner($this->host()->owner('alice')));
        $locks = $this->connection()->fetchFirstColumn('SELECT owner_id FROM ' . $this->host()->tablePrefix() . 'report_owner_lock ORDER BY owner_id');
        sort($locks);
        self::assertSame(['UserA', 'alice'], $locks);
    }

    public function testAnOwnerWhoIsNoLongerActiveIsPausedNotMailed(): void
    {
        $view = $this->subscribe('bob', new ContextRef('project', 'b1'));
        $this->host()->deactivate($this->kernel, 'bob');
        $this->clock()->modify('+1 day');
        $run    = $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs[0];
        $result = $this->runner()->run($run);
        self::assertSame([RunStatus::Paused, PauseReason::OwnerInactive], [$result->status, $result->reason]);
        self::assertSame([], $this->mailer()->sent);
        $paused = $this->service(SubscriptionsInterface::class)->get($this->host()->owner('bob'), $view->id);
        self::assertNotNull($paused);
        self::assertSame(PauseReason::OwnerInactive, $paused->pauseReason);
    }

    public function testEventsWaitForTheCommitOfAHostTransaction(): void
    {
        $connection = $this->connection();
        $listener   = $this->service(RecordingListener::class);
        $connection->beginTransaction();
        $view = $this->subscribe('alice');
        self::assertSame([], $listener->events, 'held until the host commits');
        $connection->commit();
        self::assertCount(1, $listener->events);

        $connection->beginTransaction();
        $this->service(SubscriptionsInterface::class)->setEnabled($this->host()->owner('alice'), $view->id, false);
        $connection->rollBack();
        self::assertCount(1, $listener->events, 'a rollback drops the event');
        $again = $this->service(SubscriptionsInterface::class)->get($this->host()->owner('alice'), $view->id);
        self::assertNotNull($again);
        self::assertTrue($again->enabled, 'and the change');
    }

    protected function subscribe(string $owner, ?ContextRef $context = null): SubscriptionView
    {
        return $this->service(SubscriptionsInterface::class)->create($this->host()->owner($owner), 'orders', new ReportParameters(7, $context), new Schedule(Frequency::Daily, 1, 1, 7, 'Europe/Madrid'), []);
    }

    protected function connection(): Connection
    {
        return $this->host()->entityManager($this->kernel)->getConnection();
    }

    protected function clock(): MockClock
    {
        $clock = $this->service('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    protected function dispatcher(): DueReportsDispatcher
    {
        return $this->service(DueReportsDispatcher::class);
    }

    protected function runner(): ScheduledReportRunner
    {
        return $this->service(ScheduledReportRunner::class);
    }

    protected function mailer(): RecordingMailer
    {
        return $this->service(RecordingMailer::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    protected function service(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertIsObject($service);

        return $service;
    }

    protected function claimOne(): RunScheduledReportMessage
    {
        $this->clock()->modify('+1 day');

        return $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs[0];
    }
}
