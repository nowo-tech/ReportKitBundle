<?php

declare(strict_types=1);

namespace App\Report;

use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;

/**
 * Demo permissions: `orders.view` is granted to alice on every project and to nobody else, so bob does not see the
 * "Orders by status" report (and its URL answers 403).
 */
final readonly class DemoAccessChecker implements ReportAccessCheckerInterface
{
    public function allows(OwnerRef $owner, object $user, string $type, array $contexts): array
    {
        return $owner->id === 'alice' ? $contexts : [];
    }

    public function allowsWithoutContext(OwnerRef $owner, object $user, string $type): bool
    {
        return false;
    }
}
