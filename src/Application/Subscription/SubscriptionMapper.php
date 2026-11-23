<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Subscription;

use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionId;
use Nowo\ReportKitBundle\Contract\SubscriptionView;
use Nowo\ReportKitBundle\Domain\Subscription;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Converts between the stored row ({@see Subscription}) and the contract values: the `parameters` JSON
 * (`{period_days, context: {type, id}|null, extra}`), the schedule, the formats. A row whose values no longer pass the
 * value objects (edited by hand, a stored id with edge white space) is refused with
 * {@see InvalidReportArgumentException}: it is never listed and its runs fail closed.
 *
 * @internal
 */
final class SubscriptionMapper
{
    /**
     * The stored form of the parameters.
     *
     * @return array<string, mixed>
     */
    public static function parametersToArray(ReportParameters $parameters): array
    {
        return [
            'period_days' => $parameters->periodDays,
            'context'     => $parameters->context instanceof ContextRef ? ['type' => $parameters->context->type, 'id' => $parameters->context->id] : null,
            'extra'       => (object) $parameters->extra,
        ];
    }

    /**
     * The stored parameters as {@see ReportParameters}.
     *
     * @throws InvalidReportArgumentException damaged JSON
     */
    public static function parameters(Subscription $row): ReportParameters
    {
        $stored  = $row->parameters;
        $period  = $stored['period_days'] ?? null;
        $context = $stored['context'] ?? null;
        $extra   = $stored['extra'] ?? [];
        if (!is_int($period) || !is_array($extra)) {
            throw new InvalidReportArgumentException('The stored subscription parameters are damaged.');
        }
        $ref = null;
        if ($context !== null) {
            if (!is_array($context) || !is_string($context['type'] ?? null) || !is_string($context['id'] ?? null)) {
                throw new InvalidReportArgumentException('The stored subscription context is damaged.');
            }
            $ref = new ContextRef($context['type'], $context['id']);
        }
        $values = [];
        foreach ($extra as $name => $value) {
            if (!is_string($name) || ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value))) {
                throw new InvalidReportArgumentException('The stored subscription extras are damaged.');
            }
            $values[$name] = $value;
        }

        return new ReportParameters($period, $ref, $values);
    }

    /**
     * The raw parameters a build resolves again at run time (`period`, `context`, extras): the same keys as the
     * report page's query string.
     *
     * @return array<string, bool|int|string|null>
     */
    public static function raw(ReportParameters $parameters): array
    {
        $raw = ['period' => $parameters->periodDays];
        if ($parameters->context instanceof ContextRef) {
            $raw['context'] = $parameters->context->key();
        }
        foreach ($parameters->extra as $name => $value) {
            if ($value !== null) {
                $raw[$name] = is_bool($value) ? ($value ? '1' : '0') : $value;
            }
        }

        return $raw;
    }

    /**
     * The stored schedule.
     *
     * @throws InvalidReportArgumentException damaged values
     */
    public static function schedule(Subscription $row): Schedule
    {
        $frequency = Frequency::tryFrom($row->frequency) ?? throw new InvalidReportArgumentException('The stored subscription frequency is damaged.');

        return new Schedule($frequency, $row->weekday, $row->monthDay, $row->hour, $row->timezone);
    }

    /**
     * The stored formats (unknown values dropped).
     *
     * @return list<ReportFormat>
     */
    public static function formats(Subscription $row): array
    {
        $formats = [];
        foreach ($row->formats as $value) {
            $format = ReportFormat::tryFrom($value);
            if ($format instanceof ReportFormat && $format !== ReportFormat::Json) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    /**
     * The owner of the row.
     */
    public static function owner(Subscription $row): OwnerRef
    {
        return new OwnerRef($row->ownerType, $row->ownerId);
    }

    /**
     * The view of a row.
     *
     * @throws InvalidReportArgumentException damaged values
     */
    public static function view(Subscription $row): SubscriptionView
    {
        return new SubscriptionView(
            new SubscriptionId($row->id),
            self::owner($row),
            $row->reportType,
            self::parameters($row),
            self::schedule($row),
            self::formats($row),
            $row->enabled,
            $row->pauseReason === null ? null : PauseReason::tryFrom($row->pauseReason),
            $row->nextRunAt,
            $row->lastRunAt,
            $row->lastStatus === null ? null : RunStatus::tryFrom($row->lastStatus),
            $row->lastErrorCode,
            $row->consecutiveFailures,
            $row->version,
            $row->createdAt,
        );
    }
}
