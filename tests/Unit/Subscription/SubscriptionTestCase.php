<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Subscription;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nowo\ReportKitBundle\Application\ConfigReportSettingsProvider;
use Nowo\ReportKitBundle\Application\Owner\PropertyOwnerResolver;
use Nowo\ReportKitBundle\Application\ReportBuilder;
use Nowo\ReportKitBundle\Application\ReportParametersResolver;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Application\Run\DueReportsDispatcher;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Application\Subscription\Subscriptions;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\RenderedFile;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionView;
use Nowo\ReportKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ReportKitBundle\Infrastructure\Persistence\DbalSubscriptionStore;
use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\ConnectionRegistry;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\MemoryDirectory;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\RecordingMailer;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\Schema;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\StubLinks;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\PersonalReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\RevenueReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamAccessChecker;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamScopeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;

use function str_repeat;

/**
 * The S3 application services over the real DBAL store on in-memory SQLite: the team scope resolver and access
 * checker of the web tests, the fixture types (`orders`, `revenue` with a permission, `personal`), a people
 * directory, a recording mailer and a stub exporter. Every event is recorded with its name.
 */
abstract class SubscriptionTestCase extends TestCase
{
    /** Monday 2026-11-23 06:00 UTC (07:00 in Madrid). */
    public const NOW = '2026-11-23T06:00:00+00:00';

    protected Connection $connection;

    protected DbalSubscriptionStore $store;

    protected MockClock $clock;

    protected EventDispatcher $events;

    /** @var list<array{string, object}> */
    protected array $recorded = [];

    protected MemoryDirectory $directory;

    protected RecordingMailer $mailer;

    protected Translator $translator;

    /** @var list<ReportFormat> */
    protected array $formats = [ReportFormat::Csv, ReportFormat::Xlsx, ReportFormat::Pdf];

    protected int $fileSize = 10;

    protected AfterCommitQueue $afterCommit;

    protected function setUp(): void
    {
        $this->connection = Schema::sqlite();
        Schema::create($this->connection);
        $this->afterCommit = new AfterCommitQueue();
        $this->store       = new DbalSubscriptionStore(new ConnectionRegistry($this->connection), $this->afterCommit);
        $this->clock       = new MockClock(new DateTimeImmutable(self::NOW));
        $this->events      = new EventDispatcher();
        $this->directory   = (new MemoryDirectory())->add('alice')->add('bob', locale: 'es');
        $this->mailer      = new RecordingMailer();
        $this->translator  = new Translator('en');
        foreach (['nowo_report_kit.subscription_changed', 'nowo_report_kit.scheduled_report_sent', 'nowo_report_kit.scheduled_report_paused', 'nowo_report_kit.scheduled_report_failed'] as $name) {
            $this->events->addListener($name, function (object $event) use ($name): void {
                $this->recorded[] = [$name, $event];
            });
        }
    }

    /**
     * @return list<string>
     */
    protected function eventNames(): array
    {
        $names = [];
        foreach ($this->recorded as [$name, $event]) {
            $names[] = $name . ':' . $event::class;
        }

        return $names;
    }

    /**
     * @param list<ReportTypeInterface>|null $types
     */
    protected function registry(?array $types = null, array $disabled = []): ReportTypeRegistry
    {
        return new ReportTypeRegistry($types ?? [new OrdersReportType($this->translator), new RevenueReportType($this->translator), new PersonalReportType($this->translator)], [], $disabled);
    }

    protected function subscriptions(int $max = 10, array $frequencies = ['daily', 'weekly', 'monthly'], ?ReportTypeRegistry $registry = null): Subscriptions
    {
        return new Subscriptions($this->store, $registry ?? $this->registry(), $this->settings(), $this->clock, $max, $frequencies, $this->events);
    }

    protected function settings(bool $schedules = true): ConfigReportSettingsProvider
    {
        $values = [];
        foreach ($this->formats as $format) {
            $values[] = $format->value;
        }

        return new ConfigReportSettingsProvider(true, $values, $schedules);
    }

    protected function builder(?ReportTypeRegistry $registry = null, ?ReportScopeResolverInterface $scope = null, ?ReportAccessCheckerInterface $access = null, ?EventDispatcher $events = null): ReportBuilder
    {
        return new ReportBuilder($registry ?? $this->registry(), new ReportParametersResolver(), $scope ?? new TeamScopeResolver(), $access ?? new TeamAccessChecker(), $this->clock, 'UTC', $events);
    }

    protected function exporter(): ReportExporterInterface
    {
        $formats = $this->formats;
        $size    = $this->fileSize;

        return new class($formats, $size) implements ReportExporterInterface {
            /** @param list<ReportFormat> $formats */
            public function __construct(private readonly array $formats, private readonly int $size)
            {
            }

            public function export(Report $report, ReportFormat $format): RenderedFile
            {
                return RenderedFile::fromString('report-' . $format->value . '.' . $format->extension(), $format->contentType(), str_repeat('x', $this->size));
            }

            public function formats(): array
            {
                return $this->formats;
            }
        };
    }

    protected function runner(?ReportTypeRegistry $registry = null, ?ReportScopeResolverInterface $scope = null, ?ReportAccessCheckerInterface $access = null, int $maxFailures = 3, int $maxBytes = 8388608, ?ClockInterface $clock = null, ?EventDispatcher $builderEvents = null): ScheduledReportRunner
    {
        $registry ??= $this->registry();

        return new ScheduledReportRunner(
            $this->store,
            $registry,
            $this->builder($registry, $scope, $access, $builderEvents),
            $this->exporter(),
            $this->directory,
            new PropertyOwnerResolver('user', null, 'userIdentifier'),
            $this->mailer,
            new StubLinks(),
            $clock ?? $this->clock,
            $maxFailures,
            30,
            $maxBytes,
            new LocaleSwitcher('en', [$this->translator]),
            $this->events,
        );
    }

    protected function dispatcher(bool $schedules = true, int $batch = 200, ?ClockInterface $clock = null, int $maxSeconds = 50): DueReportsDispatcher
    {
        return new DueReportsDispatcher($this->store, $this->settings($schedules), $clock ?? $this->clock, $batch, 30, $maxSeconds);
    }

    protected static function owner(string $id = 'alice'): OwnerRef
    {
        return new OwnerRef('user', $id);
    }

    /**
     * Creates a daily 07:00 Europe/Madrid subscription of alice (first slot 2026-11-24 06:00 UTC unless the clock moved).
     *
     * @param list<ReportFormat> $formats
     */
    protected function subscribe(string $type = 'orders', ?ContextRef $context = new ContextRef('project', 'a1'), array $formats = [ReportFormat::Csv], string $owner = 'alice', ?Schedule $schedule = null): SubscriptionView
    {
        return $this->subscriptions()->create(self::owner($owner), $type, new ReportParameters(30, $context), $schedule ?? new Schedule(Frequency::Daily, 1, 1, 7, 'Europe/Madrid'), $formats);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM nowo_report_subscription WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function runs(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM nowo_report_run ORDER BY slot_at');
    }
}
