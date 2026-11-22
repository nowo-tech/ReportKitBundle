<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Application;

use DOMDocument;
use Nowo\ReportKitBundle\Application\Chart\ReportChartSvg;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function substr;
use function substr_count;

final class ReportChartSvgTest extends TestCase
{
    public function testStackedColumnsAreValidXmlWithEscapedLabels(): void
    {
        $svg = ReportChartSvg::stackedColumns([
            ['2026-11-20', 3, 1],
            ['<script>alert(1)</script>&"\'', 1500, 0],
            ['2026-11-22', 2.5, 'x'],
        ], ['#123456', '"/><script>']);

        self::assertTrue((new DOMDocument())->loadXML($svg));
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
        self::assertStringContainsString('20/11', $svg);
        self::assertStringContainsString('fill="#123456"', $svg);
        self::assertStringContainsString('fill="' . ReportChartSvg::FALLBACK . '"', $svg);
        self::assertStringContainsString('1.5k', $svg);
    }

    public function testDarkVariantAndEmptyAndLargeSeries(): void
    {
        self::assertStringContainsString('#3a4046', ReportChartSvg::stackedColumns([['d', 1]], ['#000000'], true));
        $empty = ReportChartSvg::stackedColumns([], []);
        self::assertTrue((new DOMDocument())->loadXML($empty));
        self::assertSame(0, substr_count($empty, '<rect'));
        self::assertStringContainsString('2M', ReportChartSvg::stackedColumns([['d', 1500000], ['e', 0]], ['#000000']));
        self::assertStringContainsString('>7<', ReportChartSvg::stackedColumns([['d', 7]], ['#000000']));
    }

    public function testSparklines(): void
    {
        self::assertNull(ReportChartSvg::sparkline([1], '#000000'));
        self::assertNull(ReportChartSvg::sparkline([0, 0, -1], '#000000'));
        $svg = (string) ReportChartSvg::sparkline([1, -2, 4], 'red');
        self::assertTrue((new DOMDocument())->loadXML($svg));
        self::assertStringContainsString('stroke="' . ReportChartSvg::FALLBACK . '"', $svg);
    }

    public function testColoursAndDataUris(): void
    {
        self::assertSame(['#abcdef', '#e8590c'], ReportChartSvg::colours('#abcdef', 2));
        self::assertSame([], ReportChartSvg::colours('#abcdef', -1));
        self::assertSame('<svg/>', base64_decode(substr(ReportChartSvg::dataUri('<svg/>'), 26), true));
    }
}
