<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Exception;

use RuntimeException;

/**
 * The person may not open this report type: no context grants its permission, it needs a context and none is
 * accessible, or the host listener denied it. The pages answer 403; a scheduled run pauses (S3).
 */
final class ReportAccessDeniedException extends RuntimeException implements ReportKitExceptionInterface
{
    /** No context (or no context-free grant) gives the type's permission. */
    public const NO_PERMISSION = 'no_permission';

    /** The type requires a context and none is accessible. */
    public const NO_CONTEXT = 'no_context';

    /** A `ReportAccessCheckEvent` listener denied it. */
    public const DENIED_BY_LISTENER = 'denied_by_listener';

    /** The signed-in user has no owner reference (owner resolver answered null). */
    public const NO_OWNER = 'no_owner';

    /**
     * @param string $reason one of the constants of this class
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Access to this report is denied.');
    }
}
