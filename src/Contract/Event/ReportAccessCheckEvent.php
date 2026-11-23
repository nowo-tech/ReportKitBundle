<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Event;

use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;

/**
 * Per-type access decision of one build (house access pattern: grant / deny / default), dispatched as
 * {@see ReportKitEvents::REPORT_ACCESS_CHECK} after the {@see ReportAccessCheckerInterface}
 * answered. `grant()` opens every context the scope resolver returned (never more: the scope is the ceiling);
 * `deny()` refuses the build; neither keeps the checker's answer. Mutable on purpose, one instance per build.
 */
final class ReportAccessCheckEvent
{
    /** Keep the checker's answer. */
    public const DEFAULT = 'default';

    /** Open every scope context. */
    public const GRANT = 'grant';

    /** Refuse the build. */
    public const DENY = 'deny';

    private string $decision = self::DEFAULT;

    /**
     * @param OwnerRef $owner the person
     * @param string $type the report type key
     * @param string|null $permission the type's permission (null = none)
     * @param list<ContextRef> $scope the contexts the scope resolver returned
     * @param list<ContextRef> $allowed the contexts the checker granted (all of `$scope` without a permission)
     * @param bool $allowedWithoutContext whether the build may run without a context (no scope context)
     * @param bool $scheduled built by a scheduled run
     */
    public function __construct(
        public readonly OwnerRef $owner,
        public readonly string $type,
        public readonly ?string $permission,
        public readonly array $scope,
        public readonly array $allowed,
        public readonly bool $allowedWithoutContext,
        public readonly bool $scheduled,
    ) {
    }

    /**
     * Opens every scope context (or the context-free build).
     */
    public function grant(): void
    {
        // @igor-ignore - an event object, one instance per build, never a service.
        $this->decision = self::GRANT;
    }

    /**
     * Refuses the build.
     */
    public function deny(): void
    {
        // @igor-ignore - an event object, one instance per build, never a service.
        $this->decision = self::DENY;
    }

    /**
     * One of the constants of this class.
     */
    public function decision(): string
    {
        return $this->decision;
    }
}
