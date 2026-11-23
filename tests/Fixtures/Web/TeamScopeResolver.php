<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Web;

use Nowo\ReportKitBundle\Contract\ContextChoice;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;

/**
 * Host scope resolver of the web tests: alice reads `project:a1` (Alpha) and `project:a2` (Apollo), bob `project:b1`
 * (Beta), anyone else nothing. Stateless: every answer comes from the owner reference.
 */
final readonly class TeamScopeResolver implements ReportScopeResolverInterface
{
    /** Contexts per owner id: id => label. */
    public const CONTEXTS = ['alice' => ['a1' => 'Alpha', 'a2' => 'Apollo'], 'bob' => ['b1' => 'Beta']];

    public function accessibleContexts(OwnerRef $owner, object $user, string $type): array
    {
        $choices = [];
        foreach (self::CONTEXTS[$owner->id] ?? [] as $id => $label) {
            $choices[] = new ContextChoice(new ContextRef('project', (string) $id), $label);
        }

        return $choices;
    }
}
