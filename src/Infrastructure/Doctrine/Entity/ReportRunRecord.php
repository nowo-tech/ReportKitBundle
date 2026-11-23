<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ReportKitBundle\Domain\ReportRun;

/**
 * Attribute-mapped twin of {@see ReportRun} (contract §5.1, `doctrine.mapping: attribute`). Read-only for hosts.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'report_run')]
#[ORM\Index(name: 'idx_report_run_created', columns: ['created_at', 'id'])]
#[ORM\Index(name: 'idx_report_run_owner', columns: ['owner_type', 'owner_id'])]
#[ORM\UniqueConstraint(name: 'uniq_report_run_slot', columns: ['subscription_id', 'slot_at'])]
class ReportRunRecord
{
    /**
     * Holds the column values.
     *
     * @param list<string> $formats
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: Types::STRING, length: 36)]
        public readonly string $id,
        #[ORM\Column(name: 'subscription_id', type: Types::STRING, length: 36)]
        public readonly string $subscriptionId,
        #[ORM\Column(name: 'owner_type', type: Types::STRING, length: 32)]
        public readonly string $ownerType,
        #[ORM\Column(name: 'owner_id', type: Types::STRING, length: 128)]
        public readonly string $ownerId,
        #[ORM\Column(name: 'slot_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $slotAt,
        #[ORM\Column(name: 'status', type: Types::STRING, length: 16)]
        public readonly string $status,
        #[ORM\Column(name: 'formats', type: Types::JSON)]
        public readonly array $formats,
        #[ORM\Column(name: 'bytes', type: Types::INTEGER)]
        public readonly int $bytes,
        #[ORM\Column(name: 'duration_ms', type: Types::INTEGER)]
        public readonly int $durationMs,
        #[ORM\Column(name: 'error_code', type: Types::STRING, length: 64, nullable: true)]
        public readonly ?string $errorCode,
        #[ORM\Column(name: 'attempts', type: Types::SMALLINT)]
        public readonly int $attempts,
        #[ORM\Column(name: 'lease_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public readonly ?DateTimeImmutable $leaseAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'lease_token', type: Types::STRING, length: 36, nullable: true)]
        public readonly ?string $leaseToken = null,
    ) {
    }
}
