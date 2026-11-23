<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Exception;

use RuntimeException;

/**
 * The subscription changed after the owner loaded it (a run paused it, another tab resumed it): the pause, resume or
 * delete was not applied (REQ-RUNTIME-003 rule 4, optimistic version). The pages answer 409 with the code
 * {@see self::CODE}: read again, then decide. A domain conflict: it carries no `X-Conflict-Code`.
 */
final class SubscriptionChangedException extends RuntimeException implements ReportKitExceptionInterface
{
    /** Body code of the 409 answer. */
    public const CODE = 'SUBSCRIPTION_CHANGED';

    /**
     * Constant message, no ids.
     */
    public function __construct()
    {
        parent::__construct('The subscription changed meanwhile. Reload and try again.');
    }
}
