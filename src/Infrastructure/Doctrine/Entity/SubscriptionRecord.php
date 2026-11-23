<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ReportKitBundle\Domain\Subscription;

/**
 * Attribute-mapped twin of {@see Subscription} (contract §5.1, `doctrine.mapping: attribute`): the same table and
 * columns, for hosts whose tooling only reads attributes. A test asserts both mappings produce the same schema.
 * Read-only for hosts: the bundle writes through DBAL.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'report_subscription')]
#[ORM\Index(name: 'idx_report_subscription_due', columns: ['enabled', 'next_run_at', 'id'])]
#[ORM\Index(name: 'idx_report_subscription_owner', columns: ['owner_type', 'owner_id', 'created_at'])]
class SubscriptionRecord
{
    /**
     * Holds the column values.
     *
     * @param array<string, mixed> $parameters
     * @param list<string> $formats
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: Types::STRING, length: 36)]
        public readonly string $id,
        #[ORM\Column(name: 'owner_type', type: Types::STRING, length: 32)]
        public readonly string $ownerType,
        #[ORM\Column(name: 'owner_id', type: Types::STRING, length: 128)]
        public readonly string $ownerId,
        #[ORM\Column(name: 'report_type', type: Types::STRING, length: 64)]
        public readonly string $reportType,
        #[ORM\Column(name: 'parameters', type: Types::JSON)]
        public readonly array $parameters,
        #[ORM\Column(name: 'frequency', type: Types::STRING, length: 16)]
        public readonly string $frequency,
        #[ORM\Column(name: 'weekday', type: Types::SMALLINT)]
        public readonly int $weekday,
        #[ORM\Column(name: 'month_day', type: Types::SMALLINT)]
        public readonly int $monthDay,
        #[ORM\Column(name: 'hour', type: Types::SMALLINT)]
        public readonly int $hour,
        #[ORM\Column(name: 'timezone', type: Types::STRING, length: 64)]
        public readonly string $timezone,
        #[ORM\Column(name: 'formats', type: Types::JSON)]
        public readonly array $formats,
        #[ORM\Column(name: 'enabled', type: Types::BOOLEAN)]
        public readonly bool $enabled,
        #[ORM\Column(name: 'pause_reason', type: Types::STRING, length: 32, nullable: true)]
        public readonly ?string $pauseReason,
        #[ORM\Column(name: 'next_run_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $nextRunAt,
        #[ORM\Column(name: 'claim_token', type: Types::STRING, length: 36, nullable: true)]
        public readonly ?string $claimToken,
        #[ORM\Column(name: 'claimed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public readonly ?DateTimeImmutable $claimedAt,
        #[ORM\Column(name: 'last_run_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public readonly ?DateTimeImmutable $lastRunAt,
        #[ORM\Column(name: 'last_status', type: Types::STRING, length: 16, nullable: true)]
        public readonly ?string $lastStatus,
        #[ORM\Column(name: 'last_error_code', type: Types::STRING, length: 64, nullable: true)]
        public readonly ?string $lastErrorCode,
        #[ORM\Column(name: 'consecutive_failures', type: Types::SMALLINT)]
        public readonly int $consecutiveFailures,
        #[ORM\Column(name: 'version', type: Types::INTEGER)]
        public readonly int $version,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
