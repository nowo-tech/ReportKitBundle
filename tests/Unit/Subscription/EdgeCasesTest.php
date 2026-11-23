<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Subscription;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Nowo\ReportKitBundle\Application\Owner\PropertyOwnerResolver;
use Nowo\ReportKitBundle\Application\ReportBuilder;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Application\Run\RunResult;
use Nowo\ReportKitBundle\Application\Run\RunRetention;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Application\Scope\NoContextScopeResolver;
use Nowo\ReportKitBundle\Application\Subscription\Subscriptions;
use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionChangedException;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionId;
use Nowo\ReportKitBundle\Domain\Subscription;
use Nowo\ReportKitBundle\Domain\SubscriptionStoreInterface;
use Nowo\ReportKitBundle\Infrastructure\Console\ForgetOwnerCommand;
use Nowo\ReportKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ReportKitBundle\Presentation\Routing\RouterReportLinks;
use Nowo\ReportKitBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\StubLinks;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rare paths: a failure after the hand-over, a type the builder no longer knows, a lease taken over during the
 * build, a generic build failure, damaged stored parameters, a write that lost its row without row locks, held
 * after-commit work at a boundary, and retention batches smaller than the batch size.
 */
final class EdgeCasesTest extends SubscriptionTestCase
{
    public function testAFailureAfterTheHandOverIsRethrownButNeverMadeRetryable(): void
    {
        $this->subscribe();
        $message              = $this->claim();
        $this->mailer->onSend = function (): void {
            $this->connection->executeStatement('ALTER TABLE nowo_report_run RENAME TO nowo_report_run_gone');
        };
        try {
            $this->runner()->run($message);
            self::fail('no rethrow');
        } catch (Exception $e) {
            self::assertStringContainsString('nowo_report_run', $e->getMessage());
        }
        self::assertCount(1, $this->mailer->sent);
        $this->connection->executeStatement('ALTER TABLE nowo_report_run_gone RENAME TO nowo_report_run');
        self::assertSame('sending', $this->runs()[0]['status'], 'the slot stays handed over');
        $this->mailer->onSend = null;
        self::assertSame(RunResult::DUPLICATE, $this->runner()->run($message)->code);
        self::assertCount(1, $this->mailer->sent);
    }

    public function testATypeTheBuilderNoLongerKnowsPauses(): void
    {
        $view    = $this->subscribe();
        $message = $this->claim();
        $runner  = $this->runnerWith($this->registry(), $this->builder($this->registry(disabled: ['orders'])));
        self::assertSame(PauseReason::TypeRemoved, $runner->run($message)->reason);
        self::assertSame('type_removed', $this->row($view->id->value)['pause_reason']);
    }

    public function testALeaseTakenOverDuringTheBuildStopsBeforeTheMail(): void
    {
        $registry = $this->registry([$this->type(function (): void {
            $this->connection->executeStatement("UPDATE nowo_report_run SET status = 'failed'");
        })]);
        $this->subscriptions(registry: $registry)->create(self::owner(), 'probe', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
        $message = $this->claim();
        self::assertSame(RunResult::DUPLICATE, $this->runner($registry, new NoContextScopeResolver())->run($message)->code);
        self::assertSame([], $this->mailer->sent);
    }

    public function testAGenericBuildFailureIsRecordedAsRunFailed(): void
    {
        $registry = $this->registry([$this->type(static function (): never {
            throw new RuntimeException('query failed');
        })]);
        $this->subscriptions(registry: $registry)->create(self::owner(), 'probe', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
        $message = $this->claim();
        try {
            $this->runner($registry, new NoContextScopeResolver())->run($message);
            self::fail('no rethrow');
        } catch (RuntimeException $e) {
            self::assertSame('query failed', $e->getMessage());
        }
        self::assertSame('run_failed', $this->runs()[0]['error_code']);
    }

    public function testDamagedContextsAndExtrasAreRefused(): void
    {
        $view = $this->subscribe();
        foreach (['{"period_days":7,"context":{"type":1},"extra":{}}', '{"period_days":7,"context":null,"extra":{"x":[1]}}'] as $json) {
            $this->connection->executeStatement('UPDATE nowo_report_subscription SET parameters = ?', [$json]);
            self::assertNull($this->subscriptions()->get(self::owner(), $view->id), $json);
        }
    }

    public function testAWriteThatLostItsRowWithoutRowLocksIsAChange(): void
    {
        $now   = new DateTimeImmutable(self::NOW);
        $row   = new Subscription('01933b1c-5d2e-7a3b-8c4d-00000000a001', 'user', 'alice', 'orders', ['period_days' => 7, 'context' => null, 'extra' => []], 'daily', 1, 1, 7, 'UTC', [], true, null, $now, null, null, null, null, null, 0, 1, $now, $now);
        $store = $this->createStub(SubscriptionStoreInterface::class);
        $store->method('transactional')->willReturnCallback(static fn (Closure $work): mixed => $work());
        $store->method('find')->willReturn($row);
        $store->method('update')->willReturn(false);
        $store->method('delete')->willReturn(false);
        $subscriptions = new Subscriptions($store, $this->registry(), $this->settings(), $this->clock);
        $id            = new SubscriptionId($row->id);
        foreach ([static fn (): bool => $subscriptions->setEnabled(self::owner(), $id, false), static fn (): bool => $subscriptions->delete(self::owner(), $id)] as $call) {
            try {
                $call();
                self::fail('no change reported');
            } catch (SubscriptionChangedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHeldAfterCommitWorkIsDroppedAndLoggedAtABoundary(): void
    {
        $logger = new MemoryLogger();
        $queue  = new AfterCommitQueue($logger);
        $native = new stdClass();
        $ran    = [];
        $queue->hold($native, static function () use (&$ran): void {
            $ran[] = 'held';
        });
        self::assertSame(1, $queue->heldCount());
        $queue->reset();
        self::assertSame(0, $queue->heldCount());
        $queue->committed($native);
        self::assertSame([], $ran);
        self::assertStringContainsString('dropped {count} after-commit item', $logger->records[0]['message']);
        $queue->run(static fn (): never => throw new RuntimeException('listener'));
        self::assertStringContainsString('after-commit work failed', $logger->records[1]['message']);
    }

    public function testRetentionStopsAtAShortBatch(): void
    {
        foreach (['r1', 'r2'] as $id) {
            $this->connection->insert('nowo_report_run', ['id' => $id, 'subscription_id' => 's', 'owner_type' => 'user', 'owner_id' => 'alice', 'slot_at' => '2026-01-01 0' . ($id === 'r1' ? '1' : '2') . ':00:00', 'status' => 'sent', 'formats' => '[]', 'bytes' => 0, 'duration_ms' => 0, 'attempts' => 1, 'created_at' => '2026-01-01 06:00:00']);
        }
        $report = (new RunRetention($this->store, $this->clock, 90, 10))->prune(new PruneReportRunsMessage());
        self::assertSame([2, null], [$report->deleted, $report->remainder]);
    }

    public function testForgetOwnerAndItsCommand(): void
    {
        $this->subscriptions()->create(self::owner(), 'orders', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
        $this->subscriptions()->create(self::owner('bob'), 'orders', new ReportParameters(7, null), new Schedule(Frequency::Daily), []);
        $tester = new CommandTester(new ForgetOwnerCommand($this->subscriptions()));
        self::assertSame(0, $tester->execute(['type' => 'user', 'id' => 'alice']));
        self::assertStringContainsString('1 subscription(s) and 0 run row(s) erased.', $tester->getDisplay());
        self::assertSame([], $this->subscriptions()->ofOwner(self::owner()));
        self::assertCount(1, $this->subscriptions()->ofOwner(self::owner('bob')));
        self::assertSame(1, $tester->execute(['type' => 'user', 'id' => ' alice']), 'an unusable owner id is refused');
    }

    public function testMailLinksToALocalHostAreReported(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $routes = new RouteCollection();
        $routes->add('nowo_report_kit_index', new Route('/reports'));
        $router->method('getRouteCollection')->willReturn($routes);
        $logger = new MemoryLogger();
        (new RouterReportLinks($router, true, null, '', 'localhost', 'http', $logger))->subscriptionsUrl();
        self::assertStringContainsString('links point at', $logger->records[0]['message']);
        $quiet = new MemoryLogger();
        (new RouterReportLinks($router, true, 'https://app.example.test', '', 'localhost', 'http', $quiet))->subscriptionsUrl();
        self::assertSame([], $quiet->records);
    }

    public function testInvalidMessagesNeverReachTheStore(): void
    {
        self::assertSame(RunResult::GONE, $this->runner()->run(new RunScheduledReportMessage('01933b1c-5d2e-7a3b-8c4d-00000000a001', '2026-02-30T06:00:00Z', 't'))->code);
        $this->expectException(InvalidReportArgumentException::class);
        new SubscriptionId('');
    }

    private function claim(): RunScheduledReportMessage
    {
        $this->clock->modify('+1 day');

        return $this->dispatcher()->claim(new DispatchDueReportsMessage())->runs[0];
    }

    private function runnerWith(ReportTypeRegistry $registry, ReportBuilder $builder): ScheduledReportRunner
    {
        return new ScheduledReportRunner($this->store, $registry, $builder, $this->exporter(), $this->directory, new PropertyOwnerResolver('user', null, 'userIdentifier'), $this->mailer, new StubLinks(), $this->clock, 3, 30, 8388608, new LocaleSwitcher('en', [$this->translator]), $this->events);
    }

    /**
     * A context-free type `probe` whose build runs $during first.
     */
    private function type(Closure $during): AbstractReportType
    {
        return new class($this->translator, $during) extends AbstractReportType {
            public function __construct(TranslatorInterface $translator, private readonly Closure $during)
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
                ($this->during)();

                return $this->report($request, []);
            }
        };
    }
}
