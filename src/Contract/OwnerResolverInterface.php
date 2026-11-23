<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Host SPI (contract §4.3): the {@see OwnerRef} of a host user object (the signed-in viewer). The default reads
 * `owner.id_field` of a user of `owner.class` (any class when null) under `owner.type`; a DDD host plugs its own
 * (`owner.resolver`).
 */
interface OwnerResolverInterface
{
    /**
     * The owner reference, or null when this user cannot own reports (wrong class, no identifier): the pages then
     * answer 403.
     */
    public function ownerOf(object $user): ?OwnerRef;
}
