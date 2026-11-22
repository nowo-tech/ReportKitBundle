<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function array_values;
use function count;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function strlen;
use function strtolower;

/**
 * One typed extra parameter of a report type (choice, int, bool or date). {@see self::normalise()} turns a raw value
 * (query string, stored subscription) into a valid one, or the default: an invalid value never reaches a report type.
 */
final readonly class ParameterDefinition
{
    /** Most choices of a choice parameter. */
    public const MAX_CHOICES = 100;

    /** @var list<string> */
    public array $choices;

    /**
     * @param ParameterType $type the parameter type
     * @param string $labelKey translation key of the label (domain of the report type)
     * @param bool|int|string|null $default value used when the raw value is missing or invalid; must itself be valid (null only for int and date)
     * @param list<string> $choices allowed values of a choice parameter (1 to 100 keys `^[a-z0-9][a-z0-9_.-]{0,63}$`)
     * @param int|null $min lower bound of an int parameter
     * @param int|null $max upper bound of an int parameter
     */
    public function __construct(
        public ParameterType $type,
        public string $labelKey,
        public string|int|bool|null $default = null,
        array $choices = [],
        public ?int $min = null,
        public ?int $max = null,
    ) {
        if ($labelKey === '' || strlen($labelKey) > 200) {
            throw new InvalidReportArgumentException('ParameterDefinition labelKey must be 1 to 200 bytes.');
        }
        $choices = array_values($choices);
        if (($type === ParameterType::Choice) !== ($choices !== [])) {
            throw new InvalidReportArgumentException('ParameterDefinition choices are required for a choice parameter and refused for the other types.');
        }
        if (count($choices) > self::MAX_CHOICES) {
            throw new InvalidReportArgumentException(sprintf('ParameterDefinition accepts at most %d choices.', self::MAX_CHOICES));
        }
        foreach ($choices as $choice) {
            if (!is_string($choice) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $choice) !== 1) {
                throw new InvalidReportArgumentException('ParameterDefinition choices must match ^[a-z0-9][a-z0-9_.-]{0,63}$.');
            }
        }
        if (($min !== null || $max !== null) && $type !== ParameterType::Int) {
            throw new InvalidReportArgumentException('ParameterDefinition min and max apply to int parameters only.');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidReportArgumentException('ParameterDefinition min must not be greater than max.');
        }
        $this->choices = $choices;
        if ($this->accept($default) !== $default || ($default === null && in_array($type, [ParameterType::Choice, ParameterType::Bool], true))) {
            throw new InvalidReportArgumentException('ParameterDefinition default must be a valid value of the parameter.');
        }
    }

    /**
     * The valid value for $raw, or the default. Strings from a query string are accepted for every type: `"12"` for
     * int, `"1"` / `"true"` / `"on"` / `"yes"` and `"0"` / `"false"` / `"off"` / `"no"` for bool, `Y-m-d` for date.
     */
    public function normalise(mixed $raw): string|int|bool|null
    {
        return $this->accept($raw) ?? $this->default;
    }

    private function accept(mixed $raw): string|int|bool|null
    {
        return match ($this->type) {
            ParameterType::Choice => is_string($raw) && in_array($raw, $this->choices, true) ? $raw : null,
            ParameterType::Int    => $this->int($raw),
            ParameterType::Bool   => $this->bool($raw),
            ParameterType::Date   => $this->date($raw),
        };
    }

    private function int(mixed $raw): ?int
    {
        if (is_string($raw) && preg_match('/^-?[0-9]{1,15}$/D', $raw) === 1) {
            $raw = (int) $raw;
        }
        if (!is_int($raw) || ($this->min !== null && $raw < $this->min) || ($this->max !== null && $raw > $this->max)) {
            return null;
        }

        return $raw;
    }

    private function bool(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }

        return match (strtolower($raw)) {
            '1', 'true', 'on', 'yes'  => true,
            '0', 'false', 'off', 'no' => false,
            default                   => null,
        };
    }

    private function date(mixed $raw): ?string
    {
        if (!is_string($raw) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $raw) !== 1) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $day !== false && $day->format('Y-m-d') === $raw ? $raw : null;
    }
}
