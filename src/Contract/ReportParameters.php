<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Resolved parameters of one report (contract §4.1): the day window, the context (null = every accessible context)
 * and the typed extras. Built by {@see ReportParametersResolverInterface}; stored as is by a subscription (S3).
 *
 * `$contextUnavailable` is true when a context was asked for but is not among the accessible ones (unknown or not
 * readable, never told apart): the report then covers every accessible context and says so in its filters line and
 * file name (Beacon FR-012, audit ZE-I3).
 */
final readonly class ReportParameters
{
    /**
     * @param int $periodDays day window, 1 to 366
     * @param ContextRef|null $context the effective context; null = every accessible context
     * @param array<string, bool|int|string|null> $extra extras by name
     * @param bool $contextUnavailable a requested context was not accessible and "all accessible" is used instead
     */
    public function __construct(
        public int $periodDays,
        public ?ContextRef $context,
        public array $extra = [],
        public bool $contextUnavailable = false,
    ) {
        if ($periodDays < 1 || $periodDays > ParameterSchema::MAX_PERIOD_DAYS) {
            throw new InvalidReportArgumentException('ReportParameters periodDays must be from 1 to 366.');
        }
        if ($contextUnavailable && $context instanceof ContextRef) {
            throw new InvalidReportArgumentException('ReportParameters contextUnavailable means no context.');
        }
        foreach ($extra as $name => $value) {
            Guard::key('ReportParameters extra name', (string) $name);
            if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new InvalidReportArgumentException('ReportParameters extra values must be strings, integers, booleans or null.');
            }
        }
    }
}
