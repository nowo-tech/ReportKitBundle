<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Subscription;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Application\Identity\RandomIdGenerator;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\Event\ReportSubscriptionChangedEvent;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionChangedException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionLimitException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionId;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\Contract\SubscriptionView;
use Nowo\ReportKitBundle\Domain\IdGeneratorInterface;
use Nowo\ReportKitBundle\Domain\Subscription;
use Nowo\ReportKitBundle\Domain\SubscriptionStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function in_array;

/**
 * Default {@see SubscriptionsInterface} (contract §4.2, §6.1, §6.4): one transaction per call through the store,
 * id **and** owner on every lookup (exact), the per-owner lock row before the cap is counted, an optimistic version
 * on pause / resume / delete, events after the commit. Stateless (worker mode).
 */
final readonly class Subscriptions implements SubscriptionsInterface
{
    /** Hard bound of the owner list (above any `max_per_owner`). */
    public const LIST_BOUND = 100;

    /**
     * @param int $maxPerOwner `subscriptions.max_per_owner`
     * @param list<string> $frequencies `subscriptions.frequencies`
     */
    public function __construct(
        private SubscriptionStoreInterface $store,
        private ReportTypeRegistryInterface $types,
        private ReportSettingsProviderInterface $settings,
        private ClockInterface $clock,
        private int $maxPerOwner = 10,
        private array $frequencies = ['daily', 'weekly', 'monthly'],
        private ?EventDispatcherInterface $events = null,
        private ?LoggerInterface $logger = null,
        private ?IdGeneratorInterface $ids = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function create(OwnerRef $owner, string $type, ReportParameters $parameters, Schedule $schedule, array $formats): SubscriptionView
    {
        if (!$this->types->get($type) instanceof ReportTypeInterface) {
            throw new UnknownReportTypeException($type);
        }
        if (!in_array($schedule->frequency->value, $this->frequencies, true)) {
            throw new InvalidReportArgumentException('This frequency is not allowed (subscriptions.frequencies).');
        }
        $stored = $this->formats($formats);
        $now    = $this->clock->now();
        $row    = new Subscription(
            $this->ids()->next(),
            $owner->type,
            $owner->id,
            $type,
            SubscriptionMapper::parametersToArray($parameters),
            $schedule->frequency->value,
            $schedule->weekday,
            $schedule->monthDay,
            $schedule->hour,
            $schedule->timezone,
            $stored,
            true,
            null,
            $schedule->next($now),
            null,
            null,
            null,
            null,
            null,
            0,
            1,
            $now,
            $now,
        );

        // @igor-ignore - Closure passed to one transactional() call (after-commit work held at most until the commit or the request boundary, then dropped); never stored.
        $this->store->transactional(function () use ($owner, $row): void {
            // The lock row serialises concurrent creates of one owner, so the count below sees every committed row.
            $this->store->lockOwner($owner);
            if (count($this->store->ofOwner($owner, self::LIST_BOUND + 1)) >= $this->maxPerOwner) {
                throw new SubscriptionLimitException($this->maxPerOwner);
            }
            $this->store->insert($row);
        }, function () use ($row, $owner, $type): void { // @igor-ignore - Closure passed to one transactional() call (after-commit work held at most until the commit or the request boundary, then dropped); never stored.
            $this->dispatch(new ReportSubscriptionChangedEvent(new SubscriptionId($row->id), $owner, $type, ReportSubscriptionChangedEvent::CREATED, $owner));
        });

        return SubscriptionMapper::view($row);
    }

    /**
     * {@inheritDoc}
     */
    public function ofOwner(OwnerRef $owner): array
    {
        $views = [];
        foreach ($this->store->ofOwner($owner, self::LIST_BOUND) as $row) {
            try {
                $views[] = SubscriptionMapper::view($row);
            } catch (InvalidReportArgumentException $exception) {
                ($this->logger ?? new NullLogger())->warning('nowo_report_kit: skipped a damaged subscription row {id}: {message}', ['id' => $row->id, 'message' => $exception->getMessage()]);
            }
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function get(OwnerRef $owner, SubscriptionId $id): ?SubscriptionView
    {
        $row = $this->store->find($id->value);
        if (!$row instanceof Subscription || !$this->owns($owner, $row)) {
            return null;
        }
        try {
            return SubscriptionMapper::view($row);
        } catch (InvalidReportArgumentException) {
            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setEnabled(OwnerRef $owner, SubscriptionId $id, bool $enabled, ?int $expectedVersion = null): bool
    {
        $now = $this->clock->now();
        // @igor-ignore - Closure passed to one transactional() call (after-commit work held at most until the commit or the request boundary, then dropped); never stored.
        $changed = $this->store->transactional(function () use ($owner, $id, $enabled, $expectedVersion, $now): ?Subscription {
            $row = $this->locked($owner, $id, $expectedVersion);
            if (!$row instanceof Subscription) {
                return null;
            }
            $values = $enabled
                ? ['enabled' => true, 'pause_reason' => null, 'consecutive_failures' => 0, 'next_run_at' => $this->resumeAt($row, $now)]
                : ['enabled' => false, 'pause_reason' => PauseReason::Owner->value];
            if (!$this->store->update($row->id, $values, $now, $row->version)) {
                // Only without row locks (SQLite): another writer changed the row between the read and the write.
                throw new SubscriptionChangedException();
            }

            return $row;
        }, function (?Subscription $row) use ($owner, $enabled): void { // @igor-ignore - Closure passed to one transactional() call (after-commit work held at most until the commit or the request boundary, then dropped); never stored.
            if ($row instanceof Subscription) {
                $this->dispatch(new ReportSubscriptionChangedEvent(
                    new SubscriptionId($row->id),
                    $owner,
                    $row->reportType,
                    $enabled ? ReportSubscriptionChangedEvent::ENABLED : ReportSubscriptionChangedEvent::PAUSED,
                    $owner,
                    $enabled ? null : PauseReason::Owner,
                ));
            }
        });

        return $changed instanceof Subscription;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(OwnerRef $owner, SubscriptionId $id, ?int $expectedVersion = null): bool
    {
        // @igor-ignore - Closure passed to one transactional() call (after-commit work held at most until the commit or the request boundary, then dropped); never stored.
        $deleted = $this->store->transactional(function () use ($owner, $id, $expectedVersion): ?Subscription {
            $row = $this->locked($owner, $id, $expectedVersion);
            if (!$row instanceof Subscription) {
                return null;
            }
            if (!$this->store->delete($row->id, $row->version)) {
                throw new SubscriptionChangedException();
            }

            return $row;
        }, function (?Subscription $row) use ($owner): void { // @igor-ignore - Closure passed to one transactional() call (after-commit work held at most until the commit or the request boundary, then dropped); never stored.
            if ($row instanceof Subscription) {
                $this->dispatch(new ReportSubscriptionChangedEvent(new SubscriptionId($row->id), $owner, $row->reportType, ReportSubscriptionChangedEvent::DELETED, $owner));
            }
        });

        return $deleted instanceof Subscription;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetOwner(OwnerRef $owner): array
    {
        $counts = $this->store->transactional(fn (): array => $this->store->forgetOwner($owner));
        ($this->logger ?? new NullLogger())->info('nowo_report_kit: erased {subscriptions} subscription(s) and {runs} run row(s) of an owner of type {type}.', $counts + ['type' => $owner->type]);

        return $counts;
    }

    /**
     * The owner's row, locked until the end of the transaction; null when missing or someone else's.
     *
     * @throws SubscriptionChangedException the version differs from the expected one
     */
    private function locked(OwnerRef $owner, SubscriptionId $id, ?int $expectedVersion): ?Subscription
    {
        $row = $this->store->find($id->value, true);
        if (!$row instanceof Subscription || !$this->owns($owner, $row)) {
            return null;
        }
        if ($expectedVersion !== null && $row->version !== $expectedVersion) {
            throw new SubscriptionChangedException();
        }

        return $row;
    }

    private function owns(OwnerRef $owner, Subscription $row): bool
    {
        return $row->ownerType === $owner->type && $row->ownerId === $owner->id;
    }

    /**
     * The first slot after now; a damaged schedule keeps the stored slot (the run then fails closed).
     */
    private function resumeAt(Subscription $row, DateTimeImmutable $now): DateTimeImmutable
    {
        try {
            return SubscriptionMapper::schedule($row)->next($now);
        } catch (InvalidReportArgumentException) {
            return $row->nextRunAt;
        }
    }

    /**
     * PDF first when it is enabled (Q-O7), then the asked extras that are enabled; anything else is refused.
     *
     * @param list<ReportFormat> $formats
     *
     * @return list<string>
     */
    private function formats(array $formats): array
    {
        $enabled = $this->settings->formatsEnabled();
        $stored  = in_array(ReportFormat::Pdf, $enabled, true) ? [ReportFormat::Pdf->value] : [];
        foreach ($formats as $format) {
            if ($format === ReportFormat::Json || !in_array($format, $enabled, true)) {
                throw new InvalidReportArgumentException('A subscription format is not enabled.');
            }
            if (!in_array($format->value, $stored, true)) {
                $stored[] = $format->value;
            }
        }

        return $stored;
    }

    private function dispatch(object $event): void
    {
        $this->events?->dispatch($event, ReportKitEvents::SUBSCRIPTION_CHANGED);
    }

    /**
     * The id generator (the container wires the symfony/uid UUIDv7 one; a hand-built service falls back to
     * {@see RandomIdGenerator}).
     */
    private function ids(): IdGeneratorInterface
    {
        return $this->ids ?? new RandomIdGenerator();
    }
}
