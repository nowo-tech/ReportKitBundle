<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Contract;

use ArrayObject;
use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

use function array_fill;

use const INF;
use const NAN;

final class ReportModelTest extends TestCase
{
    public function testTablesKeepScalarsOnly(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'str';
            }
        };
        $section = ReportSection::table('T', ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], [['k' => 1, 2.5, 'x', null, true, false, $stringable, new stdClass()], [NAN, INF, "\xFFok"]], highlight: [true, 'yes', true]); // @phpstan-ignore argument.type

        self::assertSame([[1, 2.5, 'x', null, 1, 0, 'str', null], [null, null, '?ok']], $section->rows);
        self::assertSame([true, false], $section->highlight);
        self::assertFalse($section->keyValue);
        self::assertNull($section->truncatedAt);
    }

    public function testKeyValuesBuildRowsTonesAndSparks(): void
    {
        $section = ReportSection::keyValues('K', 'L', 'V', 'D', [ // @phpstan-ignore argument.type
            ['label' => 'a', 'value' => 1, 'delta' => 5, 'tone' => 'good', 'spark' => [1, 'x', 3]],
            ['label' => 'b', 'value' => 'text'],
            ['label' => 'c', 'value' => null, 'delta' => null],
        ]);

        self::assertTrue($section->keyValue);
        self::assertSame(['L', 'V', 'D'], $section->headers);
        self::assertSame([['a', 1, 5], ['b', 'text', null], ['c', null, null]], $section->rows);
        self::assertSame(['good', 'none', null], $section->tones);
        self::assertSame([[1, 0, 3], [], []], $section->sparks);
    }

    public function testTheHardCapAndTheConfiguredLimitTruncate(): void
    {
        $rows    = array_fill(0, ReportSection::MAX_ROWS + 2, ['x']);
        $section = ReportSection::table('T', ['a'], $rows);
        self::assertCount(ReportSection::MAX_ROWS, $section->rows);
        self::assertSame(ReportSection::MAX_ROWS, $section->truncatedAt);

        $short = ReportSection::table('T', ['a'], [['1'], ['2'], ['3']], truncatedAt: 10, highlight: [true, true, true]);
        self::assertSame($short, $short->limitedTo(3));
        $cut = $short->limitedTo(2);
        self::assertSame([['1'], ['2']], $cut->rows);
        self::assertSame(2, $cut->truncatedAt);
        self::assertSame([true, true], $cut->highlight);

        $kv = ReportSection::keyValues('K', 'L', 'V', 'D', [['label' => 'a', 'value' => 1], ['label' => 'b', 'value' => 2]])->limitedTo(1);
        self::assertCount(1, $kv->tones);
        self::assertSame(1, $kv->truncatedAt);
    }

    #[DataProvider('badSections')]
    public function testInvalidSectionsAreRefused(callable $build): void
    {
        $this->expectException(InvalidReportArgumentException::class);
        $build();
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function badSections(): iterable
    {
        yield 'no headers' => [static fn (): ReportSection => ReportSection::table('T', [], [])];
        yield 'headers not a list' => [static fn (): ReportSection => ReportSection::table('T', ['a' => 'x'], [])]; // @phpstan-ignore argument.type
        yield 'too many headers' => [static fn (): ReportSection => ReportSection::table('T', array_fill(0, 101, 'h'), [])];
        yield 'chart' => [static fn (): ReportSection => ReportSection::table('T', ['a'], [], chart: 'pie')]; // @phpstan-ignore argument.type
        yield 'style' => [static fn (): ReportSection => ReportSection::table('T', ['a'], [], style: 'fancy')]; // @phpstan-ignore argument.type
        yield 'negative cut' => [static fn (): ReportSection => ReportSection::table('T', ['a'], [], truncatedAt: -1)];
        yield 'tone' => [static fn (): ReportSection => ReportSection::keyValues('K', 'L', 'V', 'D', [['label' => 'a', 'value' => 1, 'delta' => 1, 'tone' => 'great']])];
        yield 'too many items' => [static fn (): ReportSection => ReportSection::keyValues('K', 'L', 'V', 'D', array_fill(0, ReportSection::MAX_ROWS + 1, ['label' => 'a', 'value' => 1]))];
        yield 'limit zero' => [static fn (): ReportSection => ReportSection::table('T', ['a'], [])->limitedTo(0)];
        yield 'report section' => [static fn (): Report => new Report('T', 'F', new DateTimeImmutable(), [new ArrayObject()], 'f')]; // @phpstan-ignore argument.type
        yield 'badge tone' => [static fn (): Report => new Report('T', 'F', new DateTimeImmutable(), [], 'f', badges: [['label' => 'x', 'tone' => 'blue']])]; // @phpstan-ignore argument.type
        yield 'badge shape' => [static fn (): Report => new Report('T', 'F', new DateTimeImmutable(), [], 'f', badges: ['x'])]; // @phpstan-ignore argument.type
        yield 'protocol-relative link' => [static fn (): Report => new Report('T', 'F', new DateTimeImmutable(), [], 'f', link: '//evil.test')];
        yield 'javascript link' => [static fn (): Report => new Report('T', 'F', new DateTimeImmutable(), [], 'f', link: 'javascript:alert(1)')];
        yield 'http link' => [static fn (): Report => new Report('T', 'F', new DateTimeImmutable(), [], 'f', link: 'http://x.test/')];
        yield 'quote in link' => [static fn (): Report => new Report('T', 'F', new DateTimeImmutable(), [], 'f', link: '/a"onmouseover=x')];
        yield 'locale' => [static fn (): Report => new Report('T', 'F', new DateTimeImmutable(), [], 'f', locale: 'EN')];
    }

    /**
     * Audit ZG-L5: header texts are valid UTF-8 without control characters, so the PDF page texts and the JSON
     * export always accept them.
     */
    public function testHeaderTextsAreCleaned(): void
    {
        $report = new Report("Orders\tby\nday", "Last 7 days \u{202E}evil\x00", new DateTimeImmutable(), [], 'f', summary: "Sum\xFFmary\r", badges: [['label' => "Li\x1Bve", 'tone' => 'good']], glance: "\x7F1 order ");

        self::assertSame('Orders by day', $report->title);
        self::assertSame('Last 7 days  evil', $report->filters);
        self::assertSame('Sum?mary', $report->summary);
        self::assertSame([['label' => 'Li ve', 'tone' => 'good']], $report->badges);
        self::assertSame('1 order', $report->glance);
        self::assertNull((new Report('T', 'F', new DateTimeImmutable(), [], 'f'))->summary);
    }

    public function testSafeLinksAreKept(): void
    {
        self::assertSame('/r/1?x=2', (new Report('T', 'F', new DateTimeImmutable(), [], 'f', link: '/r/1?x=2'))->link);
        self::assertSame('https://app.test/r', (new Report('T', 'F', new DateTimeImmutable(), [], 'f', link: 'https://app.test/r'))->link);
    }

    public function testFormatsKnowTheirContentType(): void
    {
        self::assertSame('text/csv; charset=UTF-8', ReportFormat::Csv->contentType());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ReportFormat::Xlsx->contentType());
        self::assertSame('application/pdf', ReportFormat::Pdf->contentType());
        self::assertSame('application/json', ReportFormat::Json->contentType());
        self::assertSame('xlsx', ReportFormat::Xlsx->extension());
    }
}
