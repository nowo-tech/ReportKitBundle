<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function str_starts_with;

/**
 * A report as data (Beacon's class, same shape, plus the brand context and the locale): title, filters line,
 * generation time, ordered sections and the base of the file name. Renderers only read it.
 */
final readonly class Report
{
    /** Tones of a badge. */
    public const BADGE_TONES = ['good', 'bad', 'warn', 'neutral'];

    /**
     * @param string $title shown in the heading of every format
     * @param string $filters one line that states the period and the effective scope
     * @param list<ReportSection> $sections in order
     * @param string $filename base of the file name, without prefix or extension (the exporter slugs it to `[a-z0-9-]`)
     * @param string|null $summary a short text under the title (PDF, page)
     * @param list<array{label: string, tone: string}> $badges shown under the title; tone one of {@see self::BADGE_TONES}
     * @param string|null $link "Open in the app": a local path (`/…`) or an `https://` URL
     * @param string|null $glance one line of the headline figures
     * @param ContextRef|null $brandContext whose brand the files carry (the effective context; null = instance brand)
     * @param string|null $locale locale of the bundle's own texts in the files (null = the translator's current locale)
     */
    public function __construct(
        public string $title,
        public string $filters,
        public DateTimeImmutable $generatedAt,
        public array $sections,
        public string $filename,
        public ?string $summary = null,
        public array $badges = [],
        public ?string $link = null,
        public ?string $glance = null,
        public ?ContextRef $brandContext = null,
        public ?string $locale = null,
    ) {
        foreach ($sections as $section) {
            if (!$section instanceof ReportSection) {
                throw new InvalidReportArgumentException('Report sections must be ReportSection values.');
            }
        }
        foreach ($badges as $badge) {
            if (!is_array($badge) || !is_string($badge['label'] ?? null) || !in_array($badge['tone'] ?? null, self::BADGE_TONES, true)) {
                throw new InvalidReportArgumentException('Report badges must be {label, tone} with tone good, bad, warn or neutral.');
            }
        }
        if ($link !== null && !$this->isSafeLink($link)) {
            throw new InvalidReportArgumentException('Report link must be a local path or an https:// URL.');
        }
        if ($locale !== null && preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/D', $locale) !== 1) {
            throw new InvalidReportArgumentException('Report locale must be a locale such as "en" or "pt_BR".');
        }
    }

    private function isSafeLink(string $link): bool
    {
        if (preg_match('/[\x00-\x20\x7F\\\\"\'<>`]/', $link) === 1) {
            return false;
        }

        return (str_starts_with($link, '/') && !str_starts_with($link, '//')) || preg_match('#^https://[^/?\#]+#', $link) === 1;
    }
}
