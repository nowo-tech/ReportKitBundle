<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use LogicException;
use Nowo\ReportKitBundle\Domain\ReportRun;
use Nowo\ReportKitBundle\Domain\Subscription;
use Nowo\ReportKitBundle\Domain\SubscriptionOwnerLock;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity\ReportRunRecord;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity\SubscriptionOwnerLockRecord;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity\SubscriptionRecord;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function in_array;
use function is_string;
use function str_replace;

/**
 * Applies `doctrine.table_prefix` to the bundle tables and their index names (`idx_<prefix>report_subscription_due`,
 * REQ-ORM-002) and, on MySQL / MariaDB, a binary **NO PAD** collation to every identifier column (REQ-SEC-006 rule 3):
 * `utf8mb4_0900_bin` on MySQL 8, `utf8mb4_nopad_bin` on MariaDB, so `usera`, `UserA` and `usera ` never compare
 * equal. `utf8mb4_bin` (PAD SPACE) is only the fallback of MySQL 5.7 or a `server_version` without the patch number
 * (logged as a warning, rule 8); the PHP `===` re-check of every owner- and id-keyed read and the refusal of edge white
 * space in the value objects keep look-alikes apart there. PostgreSQL and SQLite compare exactly by default.
 *
 * Touches only the bundle's mapped classes (each with its attribute twin), on whichever entity manager maps them
 * (standard and DDD hosts).
 */
final readonly class ReportKitMetadataListener
{
    /** Unprefixed subscription table. */
    public const SUBSCRIPTION_TABLE = 'report_subscription';

    /** Unprefixed run table. */
    public const RUN_TABLE = 'report_run';

    /** Unprefixed per-owner create lock table (REQ-RUNTIME-003 rule 4). */
    public const LOCK_TABLE = 'report_owner_lock';

    /** Mapped class => unprefixed table. */
    public const TABLES = [
        Subscription::class                => self::SUBSCRIPTION_TABLE,
        SubscriptionRecord::class          => self::SUBSCRIPTION_TABLE,
        ReportRun::class                   => self::RUN_TABLE,
        ReportRunRecord::class             => self::RUN_TABLE,
        SubscriptionOwnerLock::class       => self::LOCK_TABLE,
        SubscriptionOwnerLockRecord::class => self::LOCK_TABLE,
    ];

    /** Identifier columns: ids, owner references, the type key and the claim token. */
    public const ID_COLUMNS = ['id', 'owner_type', 'owner_id', 'report_type', 'subscription_id', 'claim_token'];

    /**
     * The binary NO PAD collation of the platform: MariaDB `utf8mb4_nopad_bin`, MySQL 8 `utf8mb4_0900_bin`, else the
     * MySQL 5.7 fallback `utf8mb4_bin` (PAD SPACE).
     */
    public static function collation(AbstractMySQLPlatform $platform): string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform => 'utf8mb4_0900_bin',
            default                              => 'utf8mb4_bin',
        };
    }

    /**
     * @param string $tablePrefix `doctrine.table_prefix`, may be empty
     */
    public function __construct(private string $tablePrefix, private ?LoggerInterface $logger = null)
    {
    }

    /**
     * Doctrine `loadClassMetadata` listener.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $base     = self::TABLES[$metadata->getName()] ?? null;
        if ($base === null) {
            return;
        }

        $table = ['name' => $this->tablePrefix . $base];
        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $renamed = [];
            foreach ($metadata->table[$key] ?? [] as $name => $definition) {
                $renamed[is_string($name) ? str_replace('_' . $base . '_', '_' . $this->tablePrefix . $base . '_', $name) : $name] = $definition;
            }
            $table[$key] = $renamed;
        }
        $metadata->setPrimaryTable($table);

        try {
            $platform = $args->getObjectManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable $exception) {
            throw new LogicException('nowo_report_kit needs the database platform to map the identifier columns with a binary collation. Set doctrine.dbal.server_version (or make the database reachable) before warming up the cache.', 0, $exception);
        }
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }
        $collation = self::collation($platform);
        if ($collation === 'utf8mb4_bin') {
            ($this->logger ?? new NullLogger())->warning('nowo_report_kit: the identifier columns fall back to utf8mb4_bin (PAD SPACE): set doctrine.dbal.server_version with the full version (e.g. "8.4.0", "11.4.0-MariaDB") to get a NO PAD binary collation (REQ-SEC-006 rule 8).');
        }
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (in_array($mapping->columnName, self::ID_COLUMNS, true)) {
                $metadata->fieldMappings[$field]->options = ['charset' => 'utf8mb4', 'collation' => $collation] + ($mapping->options ?? []);
            }
        }
    }
}
