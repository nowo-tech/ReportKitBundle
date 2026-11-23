<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Ddd;

use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\OwnerResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportKitAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * DDD host access in one class (owner resolver, report checker, area checker): the security user maps to the owner
 * `member:<identifier>`; `ledger.view` is granted context-free to `member:bob` only; the pages open to every user
 * whose identifier is not `carol`.
 */
final readonly class LedgerAccess implements OwnerResolverInterface, ReportAccessCheckerInterface, ReportKitAccessCheckerInterface
{
    public function ownerOf(object $user): ?OwnerRef
    {
        return $user instanceof UserInterface ? new OwnerRef('member', $user->getUserIdentifier()) : null;
    }

    public function allows(OwnerRef $owner, object $user, string $type, array $contexts): array
    {
        return [];
    }

    public function allowsWithoutContext(OwnerRef $owner, object $user, string $type): bool
    {
        return $owner->equals(new OwnerRef('member', 'bob')) && $type === 'ledger';
    }

    public function canAccess(object $user): bool
    {
        return !$user instanceof UserInterface || $user->getUserIdentifier() !== 'carol';
    }
}
