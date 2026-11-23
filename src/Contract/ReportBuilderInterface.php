<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\ReportAccessDeniedException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;

/**
 * Builds reports for a person (contract §4.2): resolves the contexts through the scope resolver, narrows them through
 * the access checker, resolves the raw parameters against the type schema and runs the type. Stateless: every call
 * reads the current access.
 */
interface ReportBuilderInterface
{
    /**
     * The report types this person may open, in menu order (the list page).
     *
     * @return array<string, ReportTypeInterface>
     */
    public function available(OwnerRef $owner, object $user): array;

    /**
     * Resolves everything a build needs, without building.
     *
     * @param array<array-key, mixed> $rawParameters `period`, `context` (`<type>:<id>`) and the extras (query string or a stored subscription)
     * @param string|null $locale locale of the report texts; null = `en`
     * @param string|null $timezone IANA time zone of the person; null = `schedule.default_timezone`
     *
     * @throws UnknownReportTypeException when the type does not exist or is disabled
     * @throws ReportAccessDeniedException when the person may not open this type (no granting context, no permission)
     */
    public function prepare(string $type, OwnerRef $owner, object $user, array $rawParameters, bool $scheduled = false, ?string $locale = null, ?string $timezone = null): PreparedReport;

    /**
     * {@see self::prepare()}, then the type's build.
     *
     * @param array<array-key, mixed> $rawParameters
     *
     * @throws UnknownReportTypeException
     * @throws ReportAccessDeniedException
     */
    public function build(string $type, OwnerRef $owner, object $user, array $rawParameters, bool $scheduled = false, ?string $locale = null, ?string $timezone = null): Report;
}
