<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Scope;

use Nowo\ReportKitBundle\Contract\ContextChoice;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;

/**
 * Default {@see ReportScopeResolverInterface} (`scope.resolver: null`): no context at all. Types run on personal or
 * instance-wide data; a type whose schema requires a context is not available.
 */
final readonly class NoContextScopeResolver implements ReportScopeResolverInterface
{
    /**
     * Always empty.
     *
     * @return list<ContextChoice>
     */
    public function accessibleContexts(OwnerRef $owner, object $user, string $type): array
    {
        return [];
    }
}
