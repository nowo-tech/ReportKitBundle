<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function in_array;
use function sprintf;

/**
 * When a subscription runs (contract §4.1, §9, Q-O5): a frequency, the weekday (ISO 1 = Monday … 7 = Sunday, weekly),
 * the day of the month (1–28, monthly; 29–31 are refused so every month has the day), the hour (0–23) and the IANA
 * time zone the hour is read in.
 *
 * {@see self::next()} is Beacon's `ReportSchedule::next()` extended with hour and time zone: slots are computed in local
 * time and returned in UTC. A local hour skipped by a daylight-saving change runs at the first valid minute after
 * the gap (02:00 on the spring-forward night in Europe/Madrid runs at 03:00 CEST); a repeated local hour runs once,
 * at its first occurrence. Pure: no clock, no state.
 */
final readonly class Schedule
{
    /** Latest day of the month a monthly subscription may use. */
    public const MAX_MONTH_DAY = 28;

    /** Days searched for the next slot (a monthly slot is at most two months away). */
    private const HORIZON_DAYS = 70;

    /**
     * @param int $weekday ISO weekday 1–7 (read for weekly subscriptions; stored for all)
     * @param int $monthDay 1–28 (read for monthly subscriptions; stored for all)
     * @param int $hour 0–23, in `$timezone`
     * @param string $timezone an IANA time zone identifier
     */
    public function __construct(
        public Frequency $frequency,
        public int $weekday = 1,
        public int $monthDay = 1,
        public int $hour = 7,
        public string $timezone = 'UTC',
    ) {
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidReportArgumentException('Schedule weekday must be from 1 (Monday) to 7 (Sunday).');
        }
        if ($monthDay < 1 || $monthDay > self::MAX_MONTH_DAY) {
            throw new InvalidReportArgumentException('Schedule monthDay must be from 1 to 28.');
        }
        if ($hour < 0 || $hour > 23) {
            throw new InvalidReportArgumentException('Schedule hour must be from 0 to 23.');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidReportArgumentException('Schedule timezone must be an IANA time zone identifier.');
        }
    }

    /**
     * The first slot strictly after `$after`, in UTC.
     */
    public function next(DateTimeImmutable $after): DateTimeImmutable
    {
        $zone  = new DateTimeZone($this->timezone);
        $limit = $after->getTimestamp();
        $day   = $after->setTimezone($zone);
        for ($i = 0; $i <= self::HORIZON_DAYS; ++$i) {
            $date = $day->modify('+' . $i . ' days');
            if (!$this->matches($date)) {
                continue;
            }
            // A local time inside a spring-forward gap resolves to the first valid minute after it (PHP's rule); a
            // repeated local hour resolves to its first occurrence, and the next candidate is a later day: it runs once.
            $slot = new DateTimeImmutable(sprintf('%s %02d:00:00', $date->format('Y-m-d'), $this->hour), $zone);
            if ($slot->getTimestamp() > $limit) {
                return $slot->setTimezone(new DateTimeZone('UTC'));
            }
        }

        // @codeCoverageIgnoreStart
        throw new InvalidReportArgumentException('Schedule has no slot in the next 70 days.');
        // @codeCoverageIgnoreEnd
    }

    private function matches(DateTimeImmutable $date): bool
    {
        return match ($this->frequency) {
            Frequency::Daily   => true,
            Frequency::Weekly  => (int) $date->format('N') === $this->weekday,
            Frequency::Monthly => (int) $date->format('j') === $this->monthDay,
        };
    }
}
