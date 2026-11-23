<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Host SPI (contract §4.3): the contexts (projects, teams, accounts…) a person may report on for one report type.
 * Called per build, with the current access, and never memoised by the bundle. The default
 * (`NoContextScopeResolver`) returns none: types run without a context (personal or instance-wide data) and a type
 * whose schema requires a context is not available. A custom resolver that returns none for a person refuses that
 * person every type that takes contexts, unless explicitly granted ({@see ReportRequest::$contextFreeGrant}).
 *
 * At most {@see self::MAX_CONTEXTS} choices: a longer list refuses the build rather than truncating the scope.
 */
interface ReportScopeResolverInterface
{
    /** Most contexts one build accepts (they end up in the type's `IN (…)` list). */
    public const MAX_CONTEXTS = 5000;

    /**
     * @param OwnerRef $owner the person the report is built for
     * @param object $user the host's user object of that person
     * @param string $type the report type key
     *
     * @return list<ContextChoice> the contexts, in the order the filter form lists them
     */
    public function accessibleContexts(OwnerRef $owner, object $user, string $type): array;
}
