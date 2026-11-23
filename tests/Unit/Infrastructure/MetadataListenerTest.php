<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\ReportKitBundle\Domain\ReportRun;
use Nowo\ReportKitBundle\Domain\Subscription;
use Nowo\ReportKitBundle\Domain\SubscriptionOwnerLock;
use Nowo\ReportKitBundle\Infrastructure\Collation\ReportKitExactKeyColumns;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity\ReportRunRecord;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity\SubscriptionOwnerLockRecord;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity\SubscriptionRecord;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\ReportKitMetadataListener;
use Nowo\ReportKitBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function implode;

/**
 * {@see ReportKitMetadataListener}: table prefix on tables and index names, the binary NO PAD collation of the
 * identifier columns per platform (REQ-SEC-006 rule 3 and 8), the XML mapping and the attribute twins give the same
 * schema, and the exact-key declaration names the same columns.
 */
final class MetadataListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL 8.4'    => ['8.4.0', 'utf8mb4_0900_bin'];
        yield 'MariaDB 11.4' => ['11.4.0-MariaDB', 'utf8mb4_nopad_bin'];
        yield 'MySQL 5.7'    => ['5.7.44', 'utf8mb4_bin'];
    }

    #[DataProvider('platforms')]
    public function testIdentifierColumnsGetTheBinaryCollationOfThePlatform(string $version, string $collation): void
    {
        $logger  = new MemoryLogger();
        $manager = Schema::manager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => $version]), 'acme_', 'xml', $logger);
        $metadata = $manager->getClassMetadata(Subscription::class);

        self::assertSame('acme_report_subscription', $metadata->getTableName());
        self::assertArrayHasKey('idx_acme_report_subscription_due', $metadata->table['indexes']);
        foreach (['id', 'ownerType', 'ownerId', 'reportType', 'claimToken'] as $field) {
            self::assertSame($collation, $metadata->getFieldMapping($field)->options['collation'] ?? null, $field);
        }
        self::assertArrayNotHasKey('collation', $metadata->getFieldMapping('timezone')->options ?? []);
        self::assertSame('uniq_acme_report_run_slot', array_key_first($manager->getClassMetadata(ReportRun::class)->table['uniqueConstraints']));
        self::assertSame($collation, $manager->getClassMetadata(SubscriptionOwnerLock::class)->getFieldMapping('ownerId')->options['collation'] ?? null);
        self::assertSame($collation === 'utf8mb4_bin' ? 3 : 0, count($logger->records), 'the PAD SPACE fallback is logged (once per class)');

        $sql = implode("\n", (new SchemaTool($manager))->getCreateSchemaSql($manager->getMetadataFactory()->getAllMetadata()));
        self::assertStringContainsString('owner_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `' . $collation . '`', $sql);
    }

    public function testPostgreSqlAndSqliteNeedNoCollationAndOtherClassesAreLeftAlone(): void
    {
        foreach ([DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '16']), Schema::sqlite()] as $connection) {
            $metadata = Schema::manager($connection, '')->getClassMetadata(Subscription::class);
            self::assertSame('report_subscription', $metadata->getTableName());
            self::assertArrayNotHasKey('collation', $metadata->getFieldMapping('ownerId')->options ?? []);
        }
        $other = new \Doctrine\ORM\Mapping\ClassMetadata(\stdClass::class);
        $other->setPrimaryTable(['name' => 'host_table']);
        (new ReportKitMetadataListener('x_'))->loadClassMetadata(new \Doctrine\ORM\Event\LoadClassMetadataEventArgs($other, Schema::manager(Schema::sqlite())));
        self::assertSame('host_table', $other->getTableName());
    }

    public function testAnUnreachableDatabaseWithoutAServerVersionIsReported(): void
    {
        $manager = Schema::manager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'none']));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('server_version');
        $manager->getClassMetadata(Subscription::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versions(): iterable
    {
        yield 'SQLite' => ['sqlite'];
        yield 'MySQL'  => ['8.4.0'];
        yield 'MariaDB' => ['11.4.0-MariaDB'];
    }

    #[DataProvider('versions')]
    public function testTheXmlMappingAndTheAttributeTwinsGiveTheSameSchema(string $platform): void
    {
        $connection = static fn () => $platform === 'sqlite' ? Schema::sqlite() : DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => $platform]);
        $xml        = self::describe(Schema::manager($connection()));
        self::assertSame($xml, self::describe(Schema::manager($connection(), 'nowo_', 'attribute')));
        self::assertSame(['nowo_report_owner_lock', 'nowo_report_run', 'nowo_report_subscription'], array_keys($xml));
        self::assertSame([SubscriptionRecord::class, ReportRunRecord::class, SubscriptionOwnerLockRecord::class], [
            ReportKitMetadataListener::TABLES[SubscriptionRecord::class] === 'report_subscription' ? SubscriptionRecord::class : '',
            ReportKitMetadataListener::TABLES[ReportRunRecord::class] === 'report_run' ? ReportRunRecord::class : '',
            ReportKitMetadataListener::TABLES[SubscriptionOwnerLockRecord::class] === 'report_owner_lock' ? SubscriptionOwnerLockRecord::class : '',
        ]);
    }

    /**
     * Tables => sorted column and index descriptions (column order differs between the two drivers, not the schema).
     *
     * @return array<string, list<string>>
     */
    private static function describe(\Doctrine\ORM\EntityManagerInterface $manager): array
    {
        $schema = (new SchemaTool($manager))->getSchemaFromMetadata($manager->getMetadataFactory()->getAllMetadata());
        $tables = [];
        foreach ($schema->getTables() as $table) {
            $parts = [];
            foreach ($table->getColumns() as $column) {
                $parts[] = 'column ' . $column->getName() . ' ' . \Doctrine\DBAL\Types\Type::lookupName($column->getType()) . ' ' . $column->getLength() . ' ' . ($column->getNotnull() ? 'not null' : 'null') . ' ' . json_encode($column->getPlatformOptions());
            }
            foreach ($table->getIndexes() as $index) {
                $parts[] = 'index ' . $index->getName() . ' ' . implode(',', $index->getColumns()) . ($index->isUnique() ? ' unique' : '');
            }
            sort($parts);
            $tables[$table->getName()] = $parts;
        }
        ksort($tables);

        return $tables;
    }

    public function testTheExactKeyDeclarationMatchesTheCollatedColumns(): void
    {
        $declared = [];
        foreach ((new ReportKitExactKeyColumns('acme_'))->exactKeyColumns() as $column) {
            self::assertSame(ReportKitExactKeyColumns::OWNER, $column->owner);
            self::assertNotSame('', $column->purpose);
            $declared[] = $column->describe();
        }
        $manager  = Schema::manager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']), 'acme_');
        $collated = [];
        foreach ([Subscription::class, ReportRun::class, SubscriptionOwnerLock::class] as $class) {
            $metadata = $manager->getClassMetadata($class);
            foreach ($metadata->fieldMappings as $mapping) {
                if (isset($mapping->options['collation'])) {
                    $collated[] = $metadata->getTableName() . '.' . $mapping->columnName;
                }
            }
        }
        sort($declared);
        sort($collated);
        self::assertSame($collated, $declared);
    }

    public function testTheMappedClassesHoldTheirValues(): void
    {
        $now = new \DateTimeImmutable('2026-11-23T06:00:00Z');
        $sub = new SubscriptionRecord('i', 'user', 'a', 'orders', [], 'daily', 1, 1, 7, 'UTC', ['pdf'], true, null, $now, null, null, null, null, null, 0, 1, $now, $now);
        self::assertSame(['i', 'user', 'a', 'orders', 'daily', ['pdf'], true, 1], [$sub->id, $sub->ownerType, $sub->ownerId, $sub->reportType, $sub->frequency, $sub->formats, $sub->enabled, $sub->version]);
        self::assertSame([[], 1, 1, 7, 'UTC', null, $now, null, null, null, null, null, 0, $now, $now], [$sub->parameters, $sub->weekday, $sub->monthDay, $sub->hour, $sub->timezone, $sub->pauseReason, $sub->nextRunAt, $sub->claimToken, $sub->claimedAt, $sub->lastRunAt, $sub->lastStatus, $sub->lastErrorCode, $sub->consecutiveFailures, $sub->createdAt, $sub->updatedAt]);
        $run = new ReportRunRecord('r', 'i', 'user', 'a', $now, 'sent', ['pdf'], 3, 4, null, 1, null, $now);
        self::assertSame(['r', 'i', 'user', 'a', $now, 'sent', ['pdf'], 3, 4, null, 1, null, $now], [$run->id, $run->subscriptionId, $run->ownerType, $run->ownerId, $run->slotAt, $run->status, $run->formats, $run->bytes, $run->durationMs, $run->errorCode, $run->attempts, $run->leaseAt, $run->createdAt]);
    }
}
