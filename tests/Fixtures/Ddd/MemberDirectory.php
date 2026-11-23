<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Ddd;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ReportKitBundle\Contract\OwnerDirectoryInterface;
use Nowo\ReportKitBundle\Contract\OwnerRef;

/**
 * DDD host directory: members of the `ddd` entity manager, type `member`, Spanish locale, UTC.
 */
final readonly class MemberDirectory implements OwnerDirectoryInterface
{
    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    public function userOf(OwnerRef $owner): ?object
    {
        return $owner->type === 'member' ? $this->doctrine->getManager('ddd')->find(Member::class, $owner->id) : null;
    }

    public function isActive(OwnerRef $owner): bool
    {
        $member = $this->userOf($owner);

        return $member instanceof Member && !$member->suspended();
    }

    public function email(OwnerRef $owner): ?string
    {
        $member = $this->userOf($owner);

        return $member instanceof Member ? $member->mail() : null;
    }

    public function locale(OwnerRef $owner): string
    {
        return 'es';
    }

    public function timezone(OwnerRef $owner): ?string
    {
        return null;
    }

    public function displayName(OwnerRef $owner): ?string
    {
        return null;
    }
}
