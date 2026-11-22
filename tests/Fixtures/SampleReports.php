<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportSection;

/**
 * Fixed reports of the golden-file tests: every section kind, formula-injection cells, numbers, nulls and a host cut.
 */
final class SampleReports
{
    /** Formula triggers of the shared guard table (`REQ-QA-019`). */
    public const INJECTIONS = ['=HYPERLINK("http://x.test","y")', '+1+1', '-2+3', '@SUM(A1)', "\tTAB", "\rCR", 'ok;=1+1', 'plain'];

    public static function full(?string $locale = 'en'): Report
    {
        return new Report(
            'Orders',
            'Last 30 days · Acme',
            new DateTimeImmutable('2026-11-22T10:00:00+00:00'),
            [
                ReportSection::keyValues('Key figures', 'Figure', 'Value', 'Change (%)', [
                    ['label' => 'Orders', 'value' => 1234, 'delta' => 12, 'tone' => 'good', 'spark' => [1, 3, 2, 5]],
                    ['label' => 'Revenue', 'value' => 9876.5, 'delta' => -4, 'tone' => 'bad'],
                    ['label' => 'Top country', 'value' => 'Spain'],
                ]),
                ReportSection::table('Orders per day', ['Day', 'Web', 'Shop'], [
                    ['2026-11-20', 3, 1],
                    ['2026-11-21', 5, 0],
                    ['2026-11-22', 2, 4],
                ], chart: ReportSection::CHART_SERIES),
                ReportSection::table('By status', ['Status', 'Orders'], [['paid', 7], ['open', 3]], chart: ReportSection::CHART_SLICES),
                ReportSection::table('Customers', ['Name', 'Note', 'Amount'], [
                    [self::INJECTIONS[0], self::INJECTIONS[1], 10],
                    [self::INJECTIONS[2], self::INJECTIONS[3], 2.5],
                    [self::INJECTIONS[4], self::INJECTIONS[5], null],
                    [self::INJECTIONS[6], self::INJECTIONS[7], -3],
                ], truncatedAt: 4),
            ],
            'orders-acme-2026-11-22',
            summary: 'Orders of the Acme shop.',
            badges: [['label' => 'Live', 'tone' => 'good']],
            link: '/reports/orders',
            glance: '1,234 orders',
            brandContext: new ContextRef('project', 'acme'),
            locale: $locale,
        );
    }

    /**
     * A report with one table of $rows rows.
     */
    public static function long(int $rows, ?string $locale = 'en'): Report
    {
        $cells = [];
        for ($i = 1; $i <= $rows; ++$i) {
            $cells[] = ['row ' . $i, $i];
        }

        return new Report('Long', 'All', new DateTimeImmutable('2026-11-22T10:00:00+00:00'), [ReportSection::table('Rows', ['Label', 'N'], $cells)], 'long', locale: $locale);
    }
}
