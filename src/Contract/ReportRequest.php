<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function in_array;
use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * What a report type receives (contract §4.1). `$accessible` holds the contexts the owner may report on for this
 * type, already narrowed by the host; a type must query those contexts only (bounded aggregate queries). The VO
 * offers no way to widen them: the effective context must be one of them.
 */
final readonly class ReportRequest
{
    /** @var list<ContextRef> */
    public array $accessible;

    /**
     * @param OwnerRef $owner the person the report is built for
     * @param object $user the host's user object (for the host's own queries; never read by the bundle)
     * @param ReportParameters $parameters resolved parameters
     * @param list<ContextRef> $accessible contexts the owner may report on for this type
     * @param string $locale locale of every text of the report, e.g. `en` or `pt_BR`
     * @param string $timezone IANA time zone of the owner
     * @param DateTimeImmutable $now the build time
     * @param bool $scheduled built by a scheduled run (S3) rather than a viewer
     * @param string|null $contextLabel display name of the effective context (filters line, file name); null = its id
     */
    public function __construct(
        public OwnerRef $owner,
        public object $user,
        public ReportParameters $parameters,
        array $accessible,
        public string $locale,
        public string $timezone,
        public DateTimeImmutable $now,
        public bool $scheduled = false,
        public ?string $contextLabel = null,
    ) {
        $keys = [];
        foreach ($accessible as $context) {
            if (!$context instanceof ContextRef) {
                throw new InvalidReportArgumentException('ReportRequest accessible must hold ContextRef values.');
            }
            $keys[] = $context->key();
        }
        if ($parameters->context instanceof ContextRef && !in_array($parameters->context->key(), $keys, true)) {
            throw new InvalidReportArgumentException('ReportRequest context must be one of the accessible contexts.');
        }
        if (preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/D', $locale) !== 1) {
            throw new InvalidReportArgumentException('ReportRequest locale must be a locale such as "en" or "pt_BR".');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidReportArgumentException('ReportRequest timezone must be an IANA time zone identifier.');
        }
        if ($contextLabel !== null && ($contextLabel === '' || !mb_check_encoding($contextLabel, 'UTF-8') || mb_strlen($contextLabel) > 200)) {
            throw new InvalidReportArgumentException(sprintf('ReportRequest contextLabel must be 1 to %d UTF-8 characters.', 200));
        }
        $this->accessible = $accessible;
    }

    /**
     * The UTC day window for the period: the last N UTC days up to today, plus the previous window of the same
     * length.
     */
    public function window(): ReportWindow
    {
        $days = $this->parameters->periodDays;
        // @igor-ignore - DateTimeImmutable: setTimezone() returns a new value, nothing is mutated.
        $today = $this->now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $from  = $today->modify(sprintf('-%d days', $days - 1));
        $keys  = [];
        for ($i = 0; $i < $days; ++$i) {
            $keys[] = $from->modify(sprintf('+%d days', $i))->format('Y-m-d');
        }

        return new ReportWindow($from, $today->modify('+1 day'), $from->modify(sprintf('-%d days', $days)), $today, $keys);
    }
}
