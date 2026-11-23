<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Domain;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity\SubscriptionRecord;

/**
 * One subscription row (contract §5.1, `<prefix>report_subscription`): plain PHP, mapped by
 * `Resources/config/doctrine/Subscription.orm.xml` for the host's schema tooling (attribute twin
 * {@see SubscriptionRecord}). The bundle reads and writes it
 * through DBAL ({@see SubscriptionStoreInterface}); Doctrine never manages it.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
class Subscription
{
    /**
     * Holds the column values.
     *
     * @param array<string, mixed> $parameters `{period_days, context: {type, id}|null, extra: {...}}`
     * @param list<string> $formats format values, PDF first when attached
     */
    public function __construct(
        public readonly string $id,
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly string $reportType,
        public readonly array $parameters,
        public readonly string $frequency,
        public readonly int $weekday,
        public readonly int $monthDay,
        public readonly int $hour,
        public readonly string $timezone,
        public readonly array $formats,
        public readonly bool $enabled,
        public readonly ?string $pauseReason,
        public readonly DateTimeImmutable $nextRunAt,
        public readonly ?string $claimToken,
        public readonly ?DateTimeImmutable $claimedAt,
        public readonly ?DateTimeImmutable $lastRunAt,
        public readonly ?string $lastStatus,
        public readonly ?string $lastErrorCode,
        public readonly int $consecutiveFailures,
        public readonly int $version,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
