<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Host SPI (contract §4.3): which contexts grant a report type's permission ({@see ReportTypeInterface::permission()})
 * to a person. Only asked for types that declare a permission; types without one are open to every viewer of the
 * pages. Called per build with the current access, never memoised by the bundle.
 *
 * When a type declares a permission and no checker is configured (`scope.access_checker: null`), every build of that
 * type is refused and `cache:warmup` fails, so the gap shows at deploy time.
 */
interface ReportAccessCheckerInterface
{
    /**
     * The contexts among `$contexts` that grant the type's permission. Anything not in `$contexts` is ignored (the
     * bundle intersects the answer with the input, exact comparison), so a checker can never widen the scope.
     *
     * @param list<ContextRef> $contexts the contexts the scope resolver returned (never empty)
     *
     * @return list<ContextRef>
     */
    public function allows(OwnerRef $owner, object $user, string $type, array $contexts): array;

    /**
     * Whether the type's permission is granted without a context: the type takes no context, or the scope resolver
     * returned none (personal or instance-wide data).
     */
    public function allowsWithoutContext(OwnerRef $owner, object $user, string $type): bool;
}
