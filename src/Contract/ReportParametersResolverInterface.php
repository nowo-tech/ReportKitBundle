<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

/**
 * Turns raw parameters (query string, stored subscription) into {@see ReportParameters} for a schema and the contexts
 * the viewer may report on.
 */
interface ReportParametersResolverInterface
{
    /**
     * Unknown keys are ignored; an invalid period or extra falls back to its default; a context that is not among
     * `$accessible` falls back to "all accessible" with {@see ReportParameters::$contextUnavailable} set (never an
     * error that reveals whether it exists).
     *
     * @param array<array-key, mixed> $raw `period`, `context` (`<type>:<id>`) and the extras by name
     * @param list<ContextRef> $accessible contexts the viewer may report on for this type
     *
     * @throws InvalidReportArgumentException when the schema requires a context and none of `$accessible` was picked
     */
    public function resolve(ParameterSchema $schema, array $raw, array $accessible): ReportParameters;
}
