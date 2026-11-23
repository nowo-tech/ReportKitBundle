<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Security;

use Nowo\ReportKitBundle\Contract\ReportKitAccessCheckerInterface;

/**
 * {@see ReportKitAccessCheckerInterface} of `web_ui.security.allow_unauthenticated: true` without SecurityBundle
 * (DEMO / DEV ONLY, REQ-UI-002): grants everyone. Per-type permissions still go through the report access checker.
 */
final readonly class AllowAllReportKitAccessChecker implements ReportKitAccessCheckerInterface
{
    /**
     * Always true.
     */
    public function canAccess(object $user): bool
    {
        return true;
    }
}
