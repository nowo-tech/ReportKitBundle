<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Domain;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity\ReportRunRecord;

/**
 * One scheduled run (contract §5.1, `<prefix>report_run`): the idempotency key `(subscription_id, slot_at)` (unique)
 * and the history (status, formats, size, duration, error code). Plain PHP, mapped in XML (attribute twin
 * {@see ReportRunRecord}); written through DBAL only.
 *
 * Statuses: `running` (an attempt holds the lease since `leaseAt`), `failed` (an attempt failed before the hand-over;
 * the lease is released so a Messenger retry may take it), `sending` (the mail is being handed over: never taken
 * again), then `sent`, `skipped` or `paused`.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
class ReportRun
{
    public const RUNNING = 'running';
    public const SENDING = 'sending';

    /**
     * Holds the column values.
     *
     * @param list<string> $formats the attached formats
     */
    public function __construct(
        public readonly string $id,
        public readonly string $subscriptionId,
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly DateTimeImmutable $slotAt,
        public readonly string $status,
        public readonly array $formats,
        public readonly int $bytes,
        public readonly int $durationMs,
        public readonly ?string $errorCode,
        public readonly int $attempts,
        public readonly ?DateTimeImmutable $leaseAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
