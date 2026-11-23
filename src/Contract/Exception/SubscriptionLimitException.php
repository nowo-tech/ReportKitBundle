<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Exception;

use RuntimeException;

/**
 * The owner already has `subscriptions.max_per_owner` subscriptions (contract §6.4). The schedule dialog shows it as
 * a form error (422).
 */
final class SubscriptionLimitException extends RuntimeException implements ReportKitExceptionInterface
{
    /**
     * @param int $limit `subscriptions.max_per_owner`
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct('The subscription limit per owner is reached.');
    }
}
