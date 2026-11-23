<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Access;

use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;
use Nowo\ReportKitBundle\Infrastructure\Cache\PermissionCheckerWarmer;

/**
 * Default {@see ReportAccessCheckerInterface} (`scope.access_checker: null`): grants nothing. It is only asked for
 * types that declare a permission, so those types stay closed until the host configures a checker (fail closed;
 * {@see PermissionCheckerWarmer} fails `cache:warmup` in that case).
 */
final readonly class UnconfiguredReportAccessChecker implements ReportAccessCheckerInterface
{
    /**
     * Always empty.
     *
     * @param list<ContextRef> $contexts
     *
     * @return list<ContextRef>
     */
    public function allows(OwnerRef $owner, object $user, string $type, array $contexts): array
    {
        return [];
    }

    /**
     * Always false.
     */
    public function allowsWithoutContext(OwnerRef $owner, object $user, string $type): bool
    {
        return false;
    }
}
