<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function preg_match;

/**
 * The id of a subscription (contract §4.1): a lower-case UUID in the canonical 8-4-4-4-12 form (the bundle creates
 * UUIDv7; migrated Beacon rows keep their UUID). Compared exactly.
 */
final readonly class SubscriptionId
{
    /** Canonical lower-case UUID, anchored (REQ-SEC-006 rule 5). */
    public const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** Route requirement of the id (same form, without anchors). */
    public const REQUIREMENT = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * @param string $value a lower-case UUID
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidReportArgumentException('SubscriptionId must be a lower-case UUID.');
        }
    }

    /**
     * Exact comparison (`===`).
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
