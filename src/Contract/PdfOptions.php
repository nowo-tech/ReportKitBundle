<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function in_array;
use function mb_check_encoding;
use function mb_strlen;
use function preg_match;

/**
 * Options of one {@see PdfDocumentRendererInterface::render()} call: paper, orientation, and the texts drawn on every
 * page (footer left and right, running header from page 2). `{PAGE_NUM}` and `{PAGE_COUNT}` are replaced in the
 * footer texts. `$deadline` bounds the Dompdf run (default: now + `limits.render_seconds`).
 */
final readonly class PdfOptions
{
    /** Paper sizes. */
    public const PAPERS = ['A4', 'A3', 'letter', 'legal'];

    /** Longest page text, in characters. */
    public const MAX_TEXT = 200;

    /**
     * @param string $paper one of {@see self::PAPERS}
     * @param bool $landscape landscape instead of portrait
     * @param string|null $footerLeft text at the bottom left of every page
     * @param string|null $footerRight text at the bottom right of every page (e.g. `Page {PAGE_NUM} of {PAGE_COUNT}`)
     * @param string|null $runningHeader text at the top of every page from page 2
     * @param string $footerColor `#rrggbb` of the page texts
     * @param DateTimeImmutable|null $deadline the render stops with `ReportLimitExceededException` after it; null = now + `limits.render_seconds`
     */
    public function __construct(
        public string $paper = 'A4',
        public bool $landscape = false,
        public ?string $footerLeft = null,
        public ?string $footerRight = null,
        public ?string $runningHeader = null,
        public string $footerColor = '#868e96',
        public ?DateTimeImmutable $deadline = null,
    ) {
        if (!in_array($paper, self::PAPERS, true)) {
            throw new InvalidReportArgumentException('PdfOptions paper must be A4, A3, letter or legal.');
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/D', $footerColor) !== 1) {
            throw new InvalidReportArgumentException('PdfOptions footerColor must be #rrggbb.');
        }
        foreach ([$footerLeft, $footerRight, $runningHeader] as $text) {
            if ($text !== null && (!mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > self::MAX_TEXT || preg_match('/[\x00-\x1F\x7F]/', $text) === 1)) {
                throw new InvalidReportArgumentException('PdfOptions page texts must be at most 200 UTF-8 characters without control characters.');
            }
        }
    }
}
