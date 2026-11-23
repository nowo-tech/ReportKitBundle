<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Collation;

use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\ReportKitMetadataListener;

/**
 * Declares the identifier columns of the bundle tables (REQ-SEC-006), tag `nowo_migrations_kit.exact_key_columns`:
 * a host with MigrationsKitBundle runs `nowo:migrations:check-key-collations` and gets them asserted against its real
 * schema (binary NO PAD collation on MySQL / MariaDB). A host without it never reads the tag.
 *
 * The same columns {@see ReportKitMetadataListener} maps with the binary collation, with the configured prefix.
 * Builds the same list on every call (worker mode).
 */
final readonly class ReportKitExactKeyColumns implements ExactKeyColumnsInterface
{
    public const OWNER = 'nowo-tech/report-kit-bundle';

    /** Unprefixed table => column => purpose. */
    private const COLUMNS = [
        ReportKitMetadataListener::SUBSCRIPTION_TABLE => [
            'id'          => 'subscription id: every owner page and run looks it up with =',
            'owner_type'  => 'owner type: the owner\'s subscriptions are listed and capped by it',
            'owner_id'    => 'owner id (user UUID or host id): listing, cap and erasure key',
            'report_type' => 'report type key the run builds',
            'claim_token' => 'claim token: a run clears only its own claim',
        ],
        ReportKitMetadataListener::RUN_TABLE => [
            'id'              => 'run id: retention deletes by it',
            'subscription_id' => 'idempotency key with slot_at (unique): one mail per slot',
            'owner_type'      => 'owner type of the run history (export / erasure)',
            'owner_id'        => 'owner id of the run history (export / erasure key)',
            'lease_token'     => 'run lease token: only the attempt that holds the slot closes its row',
        ],
        ReportKitMetadataListener::LOCK_TABLE => [
            'owner_type' => 'per-owner create lock key',
            'owner_id'   => 'per-owner create lock key',
        ],
    ];

    /**
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(private string $tablePrefix = 'nowo_')
    {
    }

    /**
     * @return list<ExactKeyColumn>
     */
    public function exactKeyColumns(): array
    {
        $columns = [];
        foreach (self::COLUMNS as $table => $purposes) {
            foreach ($purposes as $column => $purpose) {
                $columns[] = new ExactKeyColumn($this->tablePrefix . $table, $column, self::OWNER, $purpose);
            }
        }

        return $columns;
    }
}
