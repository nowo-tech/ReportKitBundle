<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Security;

use Nowo\ReportKitBundle\Contract\ReportKitAccessCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default {@see ReportKitAccessCheckerInterface} (REQ-UI-002): grants when the signed-in user holds at least one of
 * `web_ui.security.access_roles`; an empty list grants every signed-in user (the firewall decides).
 */
final readonly class ConfigurableReportKitAccessChecker implements ReportKitAccessCheckerInterface
{
    /**
     * @param AuthorizationCheckerInterface $authorization the host's authorization checker
     * @param list<string> $roles `web_ui.security.access_roles`
     */
    public function __construct(private AuthorizationCheckerInterface $authorization, private array $roles)
    {
    }

    /**
     * Whether the user holds one of the roles.
     */
    public function canAccess(object $user): bool
    {
        if ($this->roles === []) {
            return true;
        }
        foreach ($this->roles as $role) {
            if ($this->authorization->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
