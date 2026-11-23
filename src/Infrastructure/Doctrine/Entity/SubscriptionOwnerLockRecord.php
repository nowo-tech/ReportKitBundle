<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ReportKitBundle\Domain\SubscriptionOwnerLock;

/**
 * Attribute-mapped twin of {@see SubscriptionOwnerLock} (REQ-RUNTIME-003 rule 4). Written through DBAL only.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_owner_lock')]
class SubscriptionOwnerLockRecord
{
    /**
     * Holds the column values.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'owner_type', type: Types::STRING, length: 32)]
        public readonly string $ownerType,
        #[ORM\Id]
        #[ORM\Column(name: 'owner_id', type: Types::STRING, length: 128)]
        public readonly string $ownerId,
        #[ORM\Column(name: 'version', type: Types::INTEGER)]
        public readonly int $version = 1,
    ) {
    }
}
