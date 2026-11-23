<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Ddd;

use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\OwnerResolverInterface;

/**
 * DDD host owner resolver: a member is `member:<id>`.
 */
final readonly class MemberOwnerResolver implements OwnerResolverInterface
{
    public function ownerOf(object $user): ?OwnerRef
    {
        return $user instanceof Member ? new OwnerRef('member', $user->memberId()) : null;
    }
}
