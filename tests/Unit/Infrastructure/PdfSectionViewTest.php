<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use Nowo\ReportKitBundle\Contract\ReportSection;
use Nowo\ReportKitBundle\Infrastructure\Rendering\PdfSectionView;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class PdfSectionViewTest extends TestCase
{
    public function testCards(): void
    {
        $view = PdfSectionView::build(ReportSection::keyValues('K', 'L', 'V', 'D', [
            ['label' => 'a', 'value' => 1234, 'delta' => 3, 'tone' => 'good', 'spark' => [1, 2]],
            ['label' => 'b', 'value' => 'txt'],
        ]), '#123456', 120, 180);

        self::assertSame('kpis', $view['kind']);
        self::assertSame('1,234', $view['cards'][0]['value']);
        self::assertFalse($view['cards'][0]['text']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string) $view['cards'][0]['spark']);
        self::assertTrue($view['cards'][1]['text']);
        self::assertNull($view['cards'][1]['spark']);
        self::assertSame('none', $view['cards'][1]['tone']);
    }

    public function testSeriesAndSlices(): void
    {
        $series = PdfSectionView::build(ReportSection::table('S', ['d', 'a', 'b'], [['2026-11-20', 1, 2], ['2026-11-21', 4, 'x']], chart: 'series'), '#123456', 120, 180);
        self::assertSame(['kind', 'section', 'svg', 'svg_dark', 'legend', 'total', 'peak', 'peak_day', 'mean'], array_keys($series));
        self::assertSame(7.0, $series['total']);
        self::assertSame(4.0, $series['peak']);
        self::assertSame('2026-11-21', $series['peak_day']);
        self::assertSame(3.5, $series['mean']);
        self::assertSame([['a', '#123456'], ['b', '#e8590c']], $series['legend']);

        $empty = PdfSectionView::build(ReportSection::table('S', ['d', 'a'], [], chart: 'series'), '#123456', 120, 180);
        self::assertNull($empty['peak_day']);
        self::assertSame(0.0, $empty['mean']);

        $slices = PdfSectionView::build(ReportSection::table('P', ['l', 'v'], [['a', 3], ['b', 1], ['c', 'x']], chart: 'slices'), 'bad', 120, 180);
        self::assertSame([75, 25, 0], array_column($slices['slices'], 'share'));
        self::assertSame([100, 33, 0], array_column($slices['slices'], 'width'));
        self::assertSame('#868e96', $slices['slices'][0]['colour']);
        $none = PdfSectionView::build(ReportSection::table('P', ['l', 'v'], [['a', 0]], chart: 'slices'), '#000000', 120, 180);
        self::assertSame(0, $none['slices'][0]['share']);
        self::assertSame(0, $none['slices'][0]['width']);
    }

    public function testTablesAreCutForThePdf(): void
    {
        $view = PdfSectionView::build(ReportSection::table('T', ['Name', 'N', 'Change (%)'], [[str_repeat('x', 20), 1, 5], ['b', 2.5, null], ['c', 3, -1]], style: 'details'), '#000000', 2, 10);

        self::assertSame('details', $view['kind']);
        self::assertSame([['xxxxxxxxx…', 1, 5], ['b', 2.5, null]], $view['rows']);
        self::assertSame(2, $view['cut']);
        self::assertSame([false, false, true], $view['percent']);
        self::assertSame([false, true, true], $view['numeric']);

        $plain = PdfSectionView::build(ReportSection::table('T', ['a'], [], truncatedAt: 9), '#000000', 2, 10);
        self::assertSame('table', $plain['kind']);
        self::assertSame(9, $plain['cut']);
        self::assertSame([false], $plain['numeric']);
    }

    public function testDisplay(): void
    {
        self::assertSame('—', PdfSectionView::display(null));
        self::assertSame('1,000', PdfSectionView::display(1000));
        self::assertSame('2.5', PdfSectionView::display(2.46));
        self::assertSame('x', PdfSectionView::display('x'));
    }
}
