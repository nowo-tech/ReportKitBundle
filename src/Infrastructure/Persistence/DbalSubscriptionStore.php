<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use JsonException;
use LogicException;
use Nowo\ReportKitBundle\Contract\Exception\ReportKitConflictException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Domain\ReportRun;
use Nowo\ReportKitBundle\Domain\Subscription;
use Nowo\ReportKitBundle\Domain\SubscriptionStoreInterface;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\ReportKitMetadataListener;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_key_exists;
use function array_values;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Default {@see SubscriptionStoreInterface}: plain DBAL on the connection of `doctrine.entity_manager` (contract §5.1,
 * REQ-ARCH-001 rule 6: the bundle never flushes, clears or resets an EntityManager).
 *
 * - Rows become {@see Subscription} / {@see ReportRun} objects by hand; nothing is managed.
 * - Writes run in a transaction opened and closed in the same call through transaction-doctrine's
 *   {@see DoctrineTransactionRunner} (savepoint-aware repair after a nested failure, REQ-RUNTIME-003 rule 3); inside a
 *   host transaction they join it and the after-commit work waits for the host's commit ({@see AfterCommitQueue}).
 * - Lock conflicts are replayed up to `$attempts` times when the store opened the transaction (never inside a host
 *   transaction), then thrown as {@see ReportKitConflictException} (`CONFLICT_001`); a lost savepoint with an
 *   unknown outcome as `CONFLICT_002`. On PostgreSQL a transaction the store opens gets `SET LOCAL lock_timeout`.
 * - Reads, and writes outside a host transaction, retry once when the connection was lost between requests.
 * - Times are stored and read as UTC. Every id- and owner-keyed result is re-checked in PHP (`===`, REQ-SEC-006).
 *
 * Stateless (worker mode): the connection is resolved per call.
 */
final readonly class DbalSubscriptionStore implements SubscriptionStoreInterface
{
    private const COLUMNS = 'id, owner_type, owner_id, report_type, parameters, frequency, weekday, month_day, hour, timezone, formats, enabled, pause_reason, next_run_at, claim_token, claimed_at, last_run_at, last_status, last_error_code, consecutive_failures, version, created_at, updated_at';

    private const RUN_COLUMNS = 'id, subscription_id, owner_type, owner_id, slot_at, status, formats, bytes, duration_ms, error_code, attempts, lease_at, created_at, lease_token';

    private string $table;

    private string $runTable;

    private string $lockTable;

    private TransientFailureClassifier $classifier;

    /**
     * @param string $entityManager `doctrine.entity_manager` (falls back to a DBAL connection of that name)
     * @param string $tablePrefix `doctrine.table_prefix`
     * @param int $attempts `concurrency.attempts`: tries of a unit the store opened, on lock conflicts (1 = no replay)
     * @param int $lockTimeoutMs `concurrency.lock_timeout_ms`: PostgreSQL `lock_timeout` of a transaction the store opens (0 = server default)
     * @param int $retryAfterSeconds `conflict_response.retry_after`: the hint carried by the conflict
     */
    public function __construct(
        private ManagerRegistry $registry,
        private AfterCommitQueue $afterCommit,
        private string $entityManager = 'default',
        string $tablePrefix = 'nowo_',
        private ?LoggerInterface $logger = null,
        private int $attempts = 3,
        private int $lockTimeoutMs = 5000,
        private int $retryAfterSeconds = 1,
    ) {
        $this->table      = $tablePrefix . ReportKitMetadataListener::SUBSCRIPTION_TABLE;
        $this->runTable   = $tablePrefix . ReportKitMetadataListener::RUN_TABLE;
        $this->lockTable  = $tablePrefix . ReportKitMetadataListener::LOCK_TABLE;
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * {@inheritDoc}
     */
    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed
    {
        $connection = $this->connection();
        $joined     = $connection->isTransactionActive();
        $result     = $joined ? $this->unit($connection, $work, 1) : $this->retrying($connection, fn (Connection $c): mixed => $this->unit($c, $work, $this->attempts));
        if (!$afterCommit instanceof Closure) {
            return $result;
        }
        $then = static function () use ($afterCommit, $result): void {
            $afterCommit($result);
        };
        $native = $joined ? $connection->getNativeConnection() : null;
        if (is_object($native) && $connection->isTransactionActive()) {
            $this->afterCommit->hold($native, $then);
        } else {
            $this->afterCommit->run($then);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function lockOwner(OwnerRef $owner): void
    {
        $connection = $this->connection();
        if (!$connection->isTransactionActive()) {
            throw new LogicException('nowo_report_kit: lockOwner() must run inside transactional().');
        }
        $update = sprintf('UPDATE %s SET version = version + 1 WHERE owner_type = ? AND owner_id = ?', $this->lockTable);
        $params = [$owner->type, $owner->id];
        if ((int) $connection->executeStatement($update, $params) > 0) {
            return;
        }
        try {
            // A savepoint: on PostgreSQL a unique violation would otherwise abort the whole transaction.
            $connection->transactional(fn (Connection $c): int|string => $c->insert($this->lockTable, ['owner_type' => $owner->type, 'owner_id' => $owner->id, 'version' => 1], ['version' => ParameterType::INTEGER]));

            return;
        } catch (UniqueConstraintViolationException) {
            // Another caller created the row first: its UPDATE below waits for that caller's commit.
        }
        if ((int) $connection->executeStatement($update, $params) === 0) {
            throw new LogicException('nowo_report_kit: the owner lock row could not be taken.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function ofOwner(OwnerRef $owner, int $limit): array
    {
        $sql  = sprintf('SELECT %s FROM %s WHERE owner_type = ? AND owner_id = ? ORDER BY created_at ASC, id ASC', self::COLUMNS, $this->table);
        $rows = $this->read(static fn (Connection $c): array => $c->fetchAllAssociative($c->getDatabasePlatform()->modifyLimitQuery($sql, $limit), [$owner->type, $owner->id]));
        $list = [];
        foreach ($rows as $row) {
            $subscription = $this->hydrate($row);
            if ($subscription instanceof Subscription && $subscription->ownerType === $owner->type && $subscription->ownerId === $owner->id) {
                $list[] = $subscription;
            }
        }

        return $list;
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $id, bool $forUpdate = false): ?Subscription
    {
        $sql          = sprintf('SELECT %s FROM %s WHERE id = ?', self::COLUMNS, $this->table) . ($forUpdate ? $this->forUpdate() : '');
        $row          = $this->read(static fn (Connection $c): array|false => $c->fetchAssociative($sql, [$id]));
        $subscription = is_array($row) ? $this->hydrate($row) : null;

        return $subscription instanceof Subscription && $subscription->id === $id ? $subscription : null;
    }

    /**
     * {@inheritDoc}
     */
    public function insert(Subscription $subscription): void
    {
        $this->connection()->insert($this->table, [
            'id'                   => $subscription->id,
            'owner_type'           => $subscription->ownerType,
            'owner_id'             => $subscription->ownerId,
            'report_type'          => $subscription->reportType,
            'parameters'           => $this->encode($subscription->parameters),
            'frequency'            => $subscription->frequency,
            'weekday'              => $subscription->weekday,
            'month_day'            => $subscription->monthDay,
            'hour'                 => $subscription->hour,
            'timezone'             => $subscription->timezone,
            'formats'              => $this->encode($subscription->formats),
            'enabled'              => $subscription->enabled,
            'pause_reason'         => $subscription->pauseReason,
            'next_run_at'          => $this->utc($subscription->nextRunAt),
            'claim_token'          => $subscription->claimToken,
            'claimed_at'           => $this->nullableUtc($subscription->claimedAt),
            'last_run_at'          => $this->nullableUtc($subscription->lastRunAt),
            'last_status'          => $subscription->lastStatus,
            'last_error_code'      => $subscription->lastErrorCode,
            'consecutive_failures' => $subscription->consecutiveFailures,
            'version'              => $subscription->version,
            'created_at'           => $this->utc($subscription->createdAt),
            'updated_at'           => $this->utc($subscription->updatedAt),
        ], [
            'weekday'              => ParameterType::INTEGER,
            'month_day'            => ParameterType::INTEGER,
            'hour'                 => ParameterType::INTEGER,
            'enabled'              => Types::BOOLEAN,
            'consecutive_failures' => ParameterType::INTEGER,
            'version'              => ParameterType::INTEGER,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function update(string $id, array $values, DateTimeImmutable $now, ?int $expectedVersion = null): bool
    {
        [$sets, $params, $types] = $this->assignments($values);
        $sets[]                  = 'version = version + 1';
        $sets[]                  = 'updated_at = ?';
        $params[]                = $this->utc($now);
        $types[]                 = ParameterType::STRING;
        $params[]                = $id;
        $types[]                 = ParameterType::STRING;
        $where                   = 'id = ?';
        if ($expectedVersion !== null) {
            $where .= ' AND version = ?';
            $params[] = $expectedVersion;
            $types[]  = ParameterType::INTEGER;
        }

        return (int) $this->connection()->executeStatement(sprintf('UPDATE %s SET %s WHERE %s', $this->table, implode(', ', $sets), $where), $params, $types) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $id, ?int $expectedVersion = null): bool
    {
        $criteria = $expectedVersion === null ? ['id' => $id] : ['id' => $id, 'version' => $expectedVersion];

        return (int) $this->connection()->delete($this->table, $criteria, ['version' => ParameterType::INTEGER]) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function due(DateTimeImmutable $now, int $limit, ?string $after): array
    {
        $sql    = sprintf('SELECT %s FROM %s WHERE enabled = ? AND next_run_at <= ?%s ORDER BY id ASC', self::COLUMNS, $this->table, $after === null ? '' : ' AND id > ?');
        $params = [true, $this->utc($now)];
        $types  = [Types::BOOLEAN, ParameterType::STRING];
        if ($after !== null) {
            $params[] = $after;
            $types[]  = ParameterType::STRING;
        }
        $rows = $this->read(static fn (Connection $c): array => $c->fetchAllAssociative($c->getDatabasePlatform()->modifyLimitQuery($sql, $limit), $params, $types));
        $due  = [];
        foreach ($rows as $row) {
            $subscription = $this->hydrate($row);
            if ($subscription instanceof Subscription) {
                $due[] = $subscription;
            }
        }

        return $due;
    }

    /**
     * {@inheritDoc}
     */
    public function isTransactionActive(): bool
    {
        return $this->connection()->isTransactionActive();
    }

    /**
     * {@inheritDoc}
     */
    public function claim(string $id, DateTimeImmutable $slot, DateTimeImmutable $next, string $token, DateTimeImmutable $now, DateTimeImmutable $stale): bool
    {
        $sql = sprintf(
            'UPDATE %s SET claim_token = ?, claimed_at = ?, next_run_at = ?, version = version + 1, updated_at = ? WHERE id = ? AND enabled = ? AND next_run_at = ? AND (claim_token IS NULL OR claimed_at < ?)',
            $this->table,
        );

        return (int) $this->connection()->executeStatement(
            $sql,
            [$token, $this->utc($now), $this->utc($next), $this->utc($now), $id, true, $this->utc($slot), $this->utc($stale)],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, Types::BOOLEAN, ParameterType::STRING, ParameterType::STRING],
        ) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function insertRun(ReportRun $run): bool
    {
        $connection = $this->connection();
        try {
            // A savepoint: on PostgreSQL a unique violation would otherwise abort the surrounding transaction.
            $connection->transactional(fn (Connection $c): int|string => $c->insert($this->runTable, [
                'id'              => $run->id,
                'subscription_id' => $run->subscriptionId,
                'owner_type'      => $run->ownerType,
                'owner_id'        => $run->ownerId,
                'slot_at'         => $this->utc($run->slotAt),
                'status'          => $run->status,
                'formats'         => $this->encode($run->formats),
                'bytes'           => $run->bytes,
                'duration_ms'     => $run->durationMs,
                'error_code'      => $run->errorCode,
                'attempts'        => $run->attempts,
                'lease_at'        => $this->nullableUtc($run->leaseAt),
                'lease_token'     => $run->leaseToken,
                'created_at'      => $this->utc($run->createdAt),
            ], ['bytes' => ParameterType::INTEGER, 'duration_ms' => ParameterType::INTEGER, 'attempts' => ParameterType::INTEGER]));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function takeRun(string $subscriptionId, DateTimeImmutable $slot, DateTimeImmutable $now, DateTimeImmutable $stale, string $lease): bool
    {
        // Never a slot that reached `sending` (or a final status): only `failed`, or `running` with a stale or no lease.
        $sql = sprintf(
            "UPDATE %s SET status = ?, lease_at = ?, lease_token = ?, attempts = attempts + 1 WHERE subscription_id = ? AND slot_at = ? AND (status = 'failed' OR (status = ? AND (lease_at IS NULL OR lease_at < ?)))",
            $this->runTable,
        );

        return (int) $this->connection()->executeStatement($sql, [ReportRun::RUNNING, $this->utc($now), $lease, $subscriptionId, $this->utc($slot), ReportRun::RUNNING, $this->utc($stale)]) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function findRun(string $subscriptionId, DateTimeImmutable $slot): ?ReportRun
    {
        $sql = sprintf('SELECT %s FROM %s WHERE subscription_id = ? AND slot_at = ?', self::RUN_COLUMNS, $this->runTable);
        $row = $this->read(fn (Connection $c): array|false => $c->fetchAssociative($sql, [$subscriptionId, $this->utc($slot)]));
        $run = is_array($row) ? $this->hydrateRun($row) : null;

        return $run instanceof ReportRun && $run->subscriptionId === $subscriptionId ? $run : null;
    }

    /**
     * {@inheritDoc}
     */
    public function updateRun(string $subscriptionId, DateTimeImmutable $slot, array $values, array $whereStatuses = [], ?string $lease = null): bool
    {
        [$sets, $params, $types] = $this->assignments($values);
        $params[]                = $subscriptionId;
        $types[]                 = ParameterType::STRING;
        $params[]                = $this->utc($slot);
        $types[]                 = ParameterType::STRING;
        $where                   = 'subscription_id = ? AND slot_at = ?';
        if ($whereStatuses !== []) {
            $where .= ' AND status IN (?)';
            $params[] = array_values($whereStatuses);
            $types[]  = ArrayParameterType::STRING;
        }
        if ($lease !== null) {
            $where .= ' AND lease_token = ?';
            $params[] = $lease;
            $types[]  = ParameterType::STRING;
        }

        return (int) $this->connection()->executeStatement(sprintf('UPDATE %s SET %s WHERE %s', $this->runTable, implode(', ', $sets), $where), $params, $types) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetOwner(OwnerRef $owner): array
    {
        $connection = $this->connection();
        $counts     = [];
        foreach (['subscriptions' => $this->table, 'runs' => $this->runTable] as $key => $table) {
            $ids = [];
            foreach ($connection->fetchAllAssociative(sprintf('SELECT id, owner_type, owner_id FROM %s WHERE owner_type = ? AND owner_id = ?', $table), [$owner->type, $owner->id]) as $row) {
                // Exact rows only (REQ-SEC-006 rule 2): a folding collation must never erase a look-alike owner.
                if ($row['owner_type'] === $owner->type && $row['owner_id'] === $owner->id && is_string($row['id'])) {
                    $ids[] = $row['id'];
                }
            }
            $counts[$key] = $ids === [] ? 0 : (int) $connection->executeStatement(sprintf('DELETE FROM %s WHERE id IN (?)', $table), [$ids], [ArrayParameterType::STRING]);
        }
        foreach ($connection->fetchAllAssociative(sprintf('SELECT owner_type, owner_id FROM %s WHERE owner_type = ? AND owner_id = ?', $this->lockTable), [$owner->type, $owner->id]) as $row) {
            if ($row['owner_type'] === $owner->type && $row['owner_id'] === $owner->id) {
                $connection->delete($this->lockTable, ['owner_type' => $owner->type, 'owner_id' => $owner->id]);
            }
        }

        return ['subscriptions' => $counts['subscriptions'], 'runs' => $counts['runs']];
    }

    /**
     * {@inheritDoc}
     */
    public function oldRuns(DateTimeImmutable $before, int $limit, ?string $after): array
    {
        $sql    = sprintf('SELECT id FROM %s WHERE created_at < ?%s ORDER BY id ASC', $this->runTable, $after === null ? '' : ' AND id > ?');
        $params = $after === null ? [$this->utc($before)] : [$this->utc($before), $after];
        $ids    = $this->read(static fn (Connection $c): array => $c->fetchFirstColumn($c->getDatabasePlatform()->modifyLimitQuery($sql, $limit), $params));
        $list   = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $list[] = $id;
            }
        }

        return $list;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteRuns(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->connection()->executeStatement(sprintf('DELETE FROM %s WHERE id IN (?)', $this->runTable), [array_values($ids)], [ArrayParameterType::STRING]);
    }

    /**
     * One write unit through the runner: a lock conflict is replayed until `$attempts` tries are spent, then thrown as
     * a `CONFLICT_001` {@see ReportKitConflictException}; a lost savepoint with no deadlock behind it (outcome unknown)
     * as `CONFLICT_002`. Anything else is rethrown unchanged.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function unit(Connection $connection, Closure $work, int $attempts): mixed
    {
        $runner = DoctrineTransactionRunner::forConnection($connection, $this->retryAfterSeconds, $this->logger);
        $opened = !$connection->isTransactionActive() && $this->lockTimeoutMs > 0 && $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $body   = $opened ? function () use ($connection, $work): mixed {
            $connection->executeStatement(sprintf("SET LOCAL lock_timeout = '%dms'", $this->lockTimeoutMs));

            return $work();
        } : $work;
        for ($attempt = 1;; ++$attempt) {
            try {
                return $runner->run($body);
            } catch (ReportKitConflictException $conflict) {
                throw $conflict;
            } catch (TransientStorageFailure $failure) {
                $cause = $failure->getPrevious() ?? $failure;
                if ($attempt >= $attempts) {
                    throw ReportKitConflictException::retrySafe($cause, $this->classifier->classify($cause) ?? TransientFailureKind::Retryable, $attempt, $this->retryAfterSeconds);
                }
                ($this->logger ?? new NullLogger())->info('nowo_report_kit: lock conflict on attempt {attempt}, replaying the unit.', ['attempt' => $attempt, 'class' => $cause::class]);
            } catch (Throwable $failure) {
                if ($this->classifier->isCommitOutcomeUnknown($failure)) {
                    throw ReportKitConflictException::committed($failure, TransientFailureKind::Retryable, $attempt);
                }

                throw $failure;
            }
        }
    }

    /**
     * `SET` parts, parameters and types of an update.
     *
     * @param array<string, mixed> $values
     *
     * @return array{list<string>, list<mixed>, list<ParameterType|string>}
     */
    private function assignments(array $values): array
    {
        $sets   = [];
        $params = [];
        $types  = [];
        foreach ($values as $column => $value) {
            $sets[]               = $column . ' = ?';
            [$params[], $types[]] = match (true) {
                $value instanceof DateTimeImmutable => [$this->utc($value), ParameterType::STRING],
                is_bool($value)                     => [$value, Types::BOOLEAN],
                is_int($value)                      => [$value, ParameterType::INTEGER],
                is_array($value)                    => [$this->encode($value), ParameterType::STRING],
                $value === null                     => [null, ParameterType::NULL],
                default                             => [(string) $value, ParameterType::STRING], // @phpstan-ignore cast.string
            };
        }

        return [$sets, $params, $types];
    }

    private function forUpdate(): string
    {
        // SQLite has no row locks: a writer holds the database lock until it commits.
        return $this->connection()->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    /**
     * A damaged row (invalid date, wrong type) is skipped and logged: it can never be listed, claimed or run.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?Subscription
    {
        try {
            return new Subscription(
                $this->string($row, 'id'),
                $this->string($row, 'owner_type'),
                $this->string($row, 'owner_id'),
                $this->string($row, 'report_type'),
                $this->json($row['parameters'] ?? null),
                $this->string($row, 'frequency'),
                $this->int($row, 'weekday'),
                $this->int($row, 'month_day'),
                $this->int($row, 'hour'),
                $this->string($row, 'timezone'),
                $this->strings($this->json($row['formats'] ?? null)),
                (bool) $row['enabled'],
                $this->nullableString($row, 'pause_reason'),
                $this->date($this->string($row, 'next_run_at')),
                $this->nullableString($row, 'claim_token'),
                $this->nullableDate($row, 'claimed_at'),
                $this->nullableDate($row, 'last_run_at'),
                $this->nullableString($row, 'last_status'),
                $this->nullableString($row, 'last_error_code'),
                $this->int($row, 'consecutive_failures'),
                $this->int($row, 'version'),
                $this->date($this->string($row, 'created_at')),
                $this->date($this->string($row, 'updated_at')),
            );
        } catch (LogicException $exception) {
            ($this->logger ?? new NullLogger())->warning('nowo_report_kit: skipped a damaged subscription row: {message}', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRun(array $row): ?ReportRun
    {
        try {
            return new ReportRun(
                $this->string($row, 'id'),
                $this->string($row, 'subscription_id'),
                $this->string($row, 'owner_type'),
                $this->string($row, 'owner_id'),
                $this->date($this->string($row, 'slot_at')),
                $this->string($row, 'status'),
                $this->strings($this->json($row['formats'] ?? null)),
                $this->int($row, 'bytes'),
                $this->int($row, 'duration_ms'),
                $this->nullableString($row, 'error_code'),
                $this->int($row, 'attempts'),
                $this->nullableDate($row, 'lease_at'),
                $this->date($this->string($row, 'created_at')),
                $this->nullableString($row, 'lease_token'),
            );
        } catch (LogicException $exception) {
            ($this->logger ?? new NullLogger())->warning('nowo_report_kit: skipped a damaged run row: {message}', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @template T
     *
     * @param Closure(Connection): T $operation
     *
     * @return T
     */
    private function read(Closure $operation): mixed
    {
        return $this->retrying($this->connection(), $operation);
    }

    /**
     * Runs the operation, and once more on a fresh connection when the first try lost the connection.
     *
     * @template T
     *
     * @param Closure(Connection): T $operation
     *
     * @return T
     */
    private function retrying(Connection $connection, Closure $operation): mixed
    {
        try {
            return $operation($connection);
        } catch (ConnectionLost $exception) {
            if ($connection->isTransactionActive()) {
                throw $exception;
            }
            ($this->logger ?? new NullLogger())->info('nowo_report_kit: database connection lost, reconnecting once.');
            $connection->close();

            return $operation($connection);
        }
    }

    private function connection(): Connection
    {
        if (array_key_exists($this->entityManager, $this->registry->getManagerNames())) {
            $manager = $this->registry->getManager($this->entityManager);
            if ($manager instanceof EntityManagerInterface) {
                return $manager->getConnection();
            }
        }
        $connection = $this->registry->getConnection($this->entityManager);
        if (!$connection instanceof Connection) {
            throw new LogicException(sprintf('nowo_report_kit.doctrine.entity_manager "%s" names neither an ORM entity manager nor a DBAL connection.', $this->entityManager));
        }

        return $connection;
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format($this->connection()->getDatabasePlatform()->getDateTimeFormatString());
    }

    private function nullableUtc(?DateTimeImmutable $time): ?string
    {
        return $time instanceof DateTimeImmutable ? $this->utc($time) : null;
    }

    private function date(string $value): DateTimeImmutable
    {
        $utc    = new DateTimeZone('UTC');
        $format = $this->connection()->getDatabasePlatform()->getDateTimeFormatString();
        $date   = DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
        if ($date === false) {
            try {
                $date = new DateTimeImmutable($value, $utc);
            } catch (Exception $exception) {
                throw new LogicException('Invalid stored date.', 0, $exception);
            }
        }

        // @igor-ignore - DateTimeImmutable::setTimezone() returns a new value.
        return $date->setTimezone($utc);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableDate(array $row, string $column): ?DateTimeImmutable
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $this->date($value) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new LogicException(sprintf('Column "%s" is not a string.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (!is_int($value) && !is_numeric($value)) {
            throw new LogicException(sprintf('Column "%s" is not a number.', $column));
        }

        return (int) $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * @param array<mixed> $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Decoded JSON; anything damaged becomes [].
     *
     * @return array<mixed>
     */
    private function json(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
