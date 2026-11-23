<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Web;

use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;

use function in_array;

/**
 * Host access checker of the web tests: `revenue.view` is granted to alice on `project:a1` only and to bob on
 * `project:b1`; it also answers a context the scope never offered (`project:zz`), which the builder must ignore.
 * Context-free grants: only to the owner `user:root`.
 */
final readonly class TeamAccessChecker implements ReportAccessCheckerInterface
{
    /** Granting contexts per owner id. */
    public const GRANTS = ['alice' => ['a1', 'zz'], 'bob' => ['b1']];

    public function allows(OwnerRef $owner, object $user, string $type, array $contexts): array
    {
        $allowed = [new ContextRef('project', 'zz')];
        foreach ($contexts as $context) {
            if (in_array($context->id, self::GRANTS[$owner->id] ?? [], true)) {
                $allowed[] = $context;
            }
        }

        return $allowed;
    }

    public function allowsWithoutContext(OwnerRef $owner, object $user, string $type): bool
    {
        return $owner->id === 'root';
    }
}
