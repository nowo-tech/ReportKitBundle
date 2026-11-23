<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Event;

/**
 * Event names of the bundle (contract §6.5). Events are plain objects (no Symfony type) that carry ids, never report
 * content, extra parameter values or e-mail addresses.
 */
final class ReportKitEvents
{
    /**
     * {@see ReportAccessCheckEvent}: after the access checker, before a build; a listener may grant (within the scope
     * resolver's contexts) or deny.
     */
    public const REPORT_ACCESS_CHECK = 'nowo_report_kit.report_access_check';

    /** {@see ReportExportedEvent}: after an export response is built. */
    public const REPORT_EXPORTED = 'nowo_report_kit.report_exported';
}
