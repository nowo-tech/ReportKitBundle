<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Contract;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {@see Schedule::next()}: Beacon's daily / weekly / monthly cases, hour and time zone (Q-O5), and the daylight-saving
 * nights (a skipped local hour runs at the first valid minute after the gap; a repeated hour runs once).
 */
final class ScheduleTest extends TestCase
{
    /**
     * @return iterable<string, array{Schedule, string, string}>
     */
    public static function slots(): iterable
    {
        $daily = new Schedule(Frequency::Daily, hour: 7, timezone: 'UTC');
        yield 'daily before the hour'      => [$daily, '2026-11-23T06:59:59Z', '2026-11-23T07:00:00+00:00'];
        yield 'daily at the hour is next'  => [$daily, '2026-11-23T07:00:00Z', '2026-11-24T07:00:00+00:00'];
        yield 'daily after the hour'       => [$daily, '2026-11-23T08:00:00Z', '2026-11-24T07:00:00+00:00'];
        yield 'daily midnight, year end'   => [new Schedule(Frequency::Daily, hour: 0, timezone: 'UTC'), '2026-12-31T00:00:00Z', '2027-01-01T00:00:00+00:00'];

        $weekly = new Schedule(Frequency::Weekly, 1, hour: 7, timezone: 'UTC');
        yield 'weekly Monday, on Sunday'   => [$weekly, '2026-11-22T12:00:00Z', '2026-11-23T07:00:00+00:00'];
        yield 'weekly Monday, after it'    => [$weekly, '2026-11-23T07:00:01Z', '2026-11-30T07:00:00+00:00'];
        yield 'weekly Sunday'              => [new Schedule(Frequency::Weekly, 7, hour: 23, timezone: 'UTC'), '2026-11-23T00:00:00Z', '2026-11-29T23:00:00+00:00'];

        $monthly = new Schedule(Frequency::Monthly, monthDay: 28, hour: 7, timezone: 'UTC');
        yield 'monthly 28, February'       => [$monthly, '2027-02-01T00:00:00Z', '2027-02-28T07:00:00+00:00'];
        yield 'monthly 28, after it'       => [$monthly, '2027-02-28T07:00:00Z', '2027-03-28T07:00:00+00:00'];
        yield 'monthly 1, December'        => [new Schedule(Frequency::Monthly, monthDay: 1, hour: 7, timezone: 'UTC'), '2026-12-15T00:00:00Z', '2027-01-01T07:00:00+00:00'];

        $madrid = new Schedule(Frequency::Daily, hour: 7, timezone: 'Europe/Madrid');
        yield 'Madrid winter (UTC+1)'      => [$madrid, '2026-11-23T05:00:00Z', '2026-11-23T06:00:00+00:00'];
        yield 'Madrid summer (UTC+2)'      => [$madrid, '2026-07-01T04:00:00Z', '2026-07-01T05:00:00+00:00'];
        yield 'Madrid local date ahead'    => [$madrid, '2026-11-23T23:30:00Z', '2026-11-24T06:00:00+00:00'];
        yield 'Tokyo, local tomorrow'      => [new Schedule(Frequency::Weekly, 2, hour: 7, timezone: 'Asia/Tokyo'), '2026-11-23T23:00:00Z', '2026-11-30T22:00:00+00:00'];

        // Spring forward, Europe/Madrid 2026-03-29: 02:00 CET jumps to 03:00 CEST. 02:30 does not exist.
        $gap = new Schedule(Frequency::Daily, hour: 2, timezone: 'Europe/Madrid');
        yield 'spring forward: skipped hour runs after the gap' => [$gap, '2026-03-28T12:00:00Z', '2026-03-29T01:00:00+00:00'];
        yield 'spring forward: the next day is normal again'    => [$gap, '2026-03-29T01:00:00Z', '2026-03-30T00:00:00+00:00'];
        yield 'spring forward: 03:00 is unaffected'             => [new Schedule(Frequency::Daily, hour: 3, timezone: 'Europe/Madrid'), '2026-03-28T12:00:00Z', '2026-03-29T01:00:00+00:00'];

        // Fall back, Europe/Madrid 2026-10-25: 03:00 CEST goes back to 02:00 CET. 02:00–02:59 happen twice.
        $repeat = new Schedule(Frequency::Daily, hour: 2, timezone: 'Europe/Madrid');
        yield 'fall back: first occurrence'                    => [$repeat, '2026-10-24T12:00:00Z', '2026-10-25T00:00:00+00:00'];
        yield 'fall back: between the two runs once (next day)' => [$repeat, '2026-10-25T00:30:00Z', '2026-10-26T01:00:00+00:00'];
        yield 'fall back: after the second, next day'          => [$repeat, '2026-10-25T01:30:00Z', '2026-10-26T01:00:00+00:00'];

        // US spring forward 2026-03-08 02:00 EST → 03:00 EDT; weekly Sunday 02:00.
        yield 'New York weekly on the gap'  => [new Schedule(Frequency::Weekly, 7, hour: 2, timezone: 'America/New_York'), '2026-03-07T12:00:00Z', '2026-03-08T07:00:00+00:00'];
    }

    #[DataProvider('slots')]
    public function testNext(Schedule $schedule, string $after, string $expected): void
    {
        $next = $schedule->next(new DateTimeImmutable($after));
        self::assertSame($expected, $next->format(DATE_ATOM));
        self::assertSame('UTC', $next->getTimezone()->getName());
    }

    public function testEverySlotOfAYearIsStrictlyIncreasingAndOncePerDay(): void
    {
        $schedule = new Schedule(Frequency::Daily, hour: 2, timezone: 'Europe/Madrid');
        $cursor   = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $days     = [];
        for ($i = 0; $i < 365; ++$i) {
            $next = $schedule->next($cursor);
            self::assertGreaterThan($cursor->getTimestamp(), $next->getTimestamp());
            $days[] = $next->setTimezone(new \DateTimeZone('Europe/Madrid'))->format('Y-m-d');
            $cursor = $next;
        }
        self::assertCount(365, array_unique($days), 'no day twice, none skipped');
    }

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function invalid(): iterable
    {
        yield 'weekday 0'     => [0, 1, 7, 'UTC'];
        yield 'weekday 8'     => [8, 1, 7, 'UTC'];
        yield 'month day 0'   => [1, 0, 7, 'UTC'];
        yield 'month day 29'  => [1, 29, 7, 'UTC'];
        yield 'month day 31'  => [1, 31, 7, 'UTC'];
        yield 'hour -1'       => [1, 1, -1, 'UTC'];
        yield 'hour 24'       => [1, 1, 24, 'UTC'];
        yield 'time zone'     => [1, 1, 7, 'Mars/Olympus'];
        yield 'offset string' => [1, 1, 7, '+02:00'];
    }

    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(int $weekday, int $monthDay, int $hour, string $timezone): void
    {
        $this->expectException(InvalidReportArgumentException::class);
        new Schedule(Frequency::Monthly, $weekday, $monthDay, $hour, $timezone);
    }

    public function testSubscriptionIdsAreLowerCaseUuidsComparedExactly(): void
    {
        $id = new SubscriptionId('01933b1c-5d2e-7a3b-8c4d-00000000b002');
        self::assertTrue($id->equals(new SubscriptionId('01933b1c-5d2e-7a3b-8c4d-00000000b002')));
        self::assertFalse($id->equals(new SubscriptionId('01933b1c-5d2e-7a3b-8c4d-00000000b003')));
        foreach (['01933B1C-5D2E-7A3B-8C4D-00000000B002', ' 01933b1c-5d2e-7a3b-8c4d-00000000b002', "01933b1c-5d2e-7a3b-8c4d-00000000b002\n", 'x'] as $bad) {
            try {
                new SubscriptionId($bad);
                self::fail('accepted ' . $bad);
            } catch (InvalidReportArgumentException) {
            }
        }
    }
}
