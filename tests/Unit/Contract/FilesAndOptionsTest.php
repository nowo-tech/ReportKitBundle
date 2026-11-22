<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\PdfOptions;
use Nowo\ReportKitBundle\Contract\RenderedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class FilesAndOptionsTest extends TestCase
{
    public function testARenderedFileRereadsItsStream(): void
    {
        $file = RenderedFile::fromString('report-a.csv', 'text/csv', 'abc');
        self::assertSame('abc', $file->contents());
        self::assertSame('abc', $file->contents());
        self::assertSame(3, $file->size);
        self::assertSame('report-a.csv', $file->filename);
    }

    public function testARenderedFileNeedsAStreamAndASlugName(): void
    {
        try {
            new RenderedFile('a.csv', 'text/csv', 'not a stream', 1); // @phpstan-ignore argument.type
            self::fail('accepted');
        } catch (InvalidReportArgumentException) {
        }
        $this->expectException(InvalidReportArgumentException::class);
        RenderedFile::fromString('../a.csv', 'text/csv', '');
    }

    public function testPdfOptionsDefaults(): void
    {
        $options = new PdfOptions();
        self::assertSame('A4', $options->paper);
        self::assertFalse($options->landscape);
        self::assertNull($options->footerLeft);
    }

    #[DataProvider('badOptions')]
    public function testInvalidPdfOptionsAreRefused(callable $build): void
    {
        $this->expectException(InvalidReportArgumentException::class);
        $build();
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function badOptions(): iterable
    {
        yield 'paper' => [static fn (): PdfOptions => new PdfOptions('A5')];
        yield 'colour' => [static fn (): PdfOptions => new PdfOptions(footerColor: 'red')];
        yield 'control' => [static fn (): PdfOptions => new PdfOptions(footerLeft: "a\nb")];
        yield 'long' => [static fn (): PdfOptions => new PdfOptions(footerRight: str_repeat('x', 201))];
        yield 'utf-8' => [static fn (): PdfOptions => new PdfOptions(runningHeader: "\xFF")];
    }
}
