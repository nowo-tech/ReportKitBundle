<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application;

use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportParametersResolverInterface;

use function in_array;
use function is_int;
use function is_string;
use function preg_match;

/**
 * Default {@see ReportParametersResolverInterface}: stateless, every call resolves from its arguments only.
 */
final readonly class ReportParametersResolver implements ReportParametersResolverInterface
{
    /**
     * @param array<array-key, mixed> $raw
     * @param list<ContextRef> $accessible
     */
    public function resolve(ParameterSchema $schema, array $raw, array $accessible): ReportParameters
    {
        $period = $raw['period'] ?? null;
        if (is_string($period) && preg_match('/^[0-9]{1,3}$/D', $period) === 1) {
            $period = (int) $period;
        }
        $periodDays = is_int($period) && in_array($period, $schema->periods, true) ? $period : $schema->defaultPeriod;

        $context     = null;
        $unavailable = false;
        $requested   = $schema->context ? ($raw['context'] ?? null) : null;
        if (is_string($requested) && $requested !== '') {
            foreach ($accessible as $candidate) {
                // Exact comparison (`===` on type and id): `Project-A` never matches `project-a`.
                if ($candidate instanceof ContextRef && $candidate->key() === $requested) {
                    $context = $candidate;
                    break;
                }
            }
            $unavailable = !$context instanceof ContextRef;
        } elseif ($requested !== null && $requested !== '') {
            // Not a string (an array from `context[]=`): never a valid reference.
            $unavailable = true;
        }
        if ($schema->contextRequired && !$context instanceof ContextRef) {
            throw new InvalidReportArgumentException('This report type needs one context the viewer may report on.');
        }

        $extra = [];
        foreach ($schema->extra as $name => $definition) {
            $extra[$name] = $definition->normalise($raw[$name] ?? null);
        }

        return new ReportParameters($periodDays, $context, $extra, $unavailable);
    }
}
