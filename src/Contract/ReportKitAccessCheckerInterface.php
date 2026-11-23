<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Area access of the report pages (REQ-UI-002): may this signed-in user open the report pages at all? The default
 * grants when the user holds one of `web_ui.security.access_roles` (any signed-in user when the list is empty); a host
 * plugs its own through `web_ui.security.access_checker`. Per type and per context, the
 * {@see ReportAccessCheckerInterface} decides afterwards.
 */
interface ReportKitAccessCheckerInterface
{
    /**
     * Whether the user may open the report pages.
     */
    public function canAccess(object $user): bool;
}
