<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_int;
use function sprintf;

/**
 * What a report type accepts (contract §4.1): the allowed day windows, whether a context is offered or required, and
 * typed extras. It drives the filter form (S2) and the validation of raw parameters
 * ({@see ReportParametersResolverInterface}); a subscription's stored parameters are checked against it again at run
 * time (S3).
 */
final readonly class ParameterSchema
{
    /** Longest day window. */
    public const MAX_PERIOD_DAYS = 366;

    /** Most extra parameters per type. */
    public const MAX_EXTRA = 20;

    /** Names the extras cannot use (the period and context query parameters and the route's `type` / `format`). */
    public const RESERVED = ['period', 'context', 'format', 'type'];

    /** @var list<int> */
    public array $periods;

    /**
     * @param list<int> $periods allowed day windows, e.g. [7, 30, 90] (1 to 366 days, at least one)
     * @param int $defaultPeriod one of $periods
     * @param bool $context whether the type is scoped to a context the viewer picks
     * @param bool $contextRequired whether the type needs one context (never "all accessible")
     * @param array<string, ParameterDefinition> $extra typed extras keyed by name (`^[a-z][a-z0-9_]{0,31}$`, not `period`, `context` or `format`)
     */
    public function __construct(
        array $periods = [7, 30, 90],
        public int $defaultPeriod = 30,
        public bool $context = true,
        public bool $contextRequired = false,
        public array $extra = [],
    ) {
        $periods = array_values(array_unique($periods));
        if ($periods === []) {
            throw new InvalidReportArgumentException('ParameterSchema needs at least one period.');
        }
        foreach ($periods as $period) {
            if (!is_int($period) || $period < 1 || $period > self::MAX_PERIOD_DAYS) {
                throw new InvalidReportArgumentException(sprintf('ParameterSchema periods must be integers from 1 to %d days.', self::MAX_PERIOD_DAYS));
            }
        }
        if (!in_array($defaultPeriod, $periods, true)) {
            throw new InvalidReportArgumentException('ParameterSchema defaultPeriod must be one of the periods.');
        }
        if ($contextRequired && !$context) {
            throw new InvalidReportArgumentException('ParameterSchema contextRequired needs context.');
        }
        if (count($extra) > self::MAX_EXTRA) {
            throw new InvalidReportArgumentException(sprintf('ParameterSchema accepts at most %d extras.', self::MAX_EXTRA));
        }
        foreach ($extra as $name => $definition) {
            Guard::key('ParameterSchema extra name', (string) $name);
            if (in_array($name, self::RESERVED, true) || !$definition instanceof ParameterDefinition) {
                throw new InvalidReportArgumentException('ParameterSchema extras must be ParameterDefinition values under a name other than period, context or format.');
            }
        }
        $this->periods = $periods;
    }
}
