<?php

declare(strict_types=1);

namespace App\Report;

use Nowo\ReportKitBundle\Contract\ContextChoice;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;

/**
 * Demo scope: alice reports on both projects, bob on "Beta app" only.
 */
final readonly class DemoScopeResolver implements ReportScopeResolverInterface
{
    /** Projects per demo user. */
    public const MEMBERS = ['alice' => ['alpha', 'beta'], 'bob' => ['beta']];

    public function accessibleContexts(OwnerRef $owner, object $user, string $type): array
    {
        $choices = [];
        foreach (self::MEMBERS[$owner->id] ?? [] as $project) {
            $choices[] = new ContextChoice(new ContextRef('project', $project), DemoData::PROJECTS[$project]);
        }

        return $choices;
    }
}
