<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Web;

use Nowo\ReportKitBundle\Contract\ContextChoice;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;

/**
 * A scope over the 5,000-context ceiling: the builder refuses it (503 on the pages).
 */
final readonly class HugeScopeResolver implements ReportScopeResolverInterface
{
    public function accessibleContexts(OwnerRef $owner, object $user, string $type): array
    {
        $choices = [];
        for ($i = 0; $i <= self::MAX_CONTEXTS; ++$i) {
            $choices[] = new ContextChoice(new ContextRef('project', (string) $i), 'P' . $i);
        }

        return $choices;
    }
}
