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

    /** {@see ReportSubscriptionChangedEvent}: after a subscription was created, paused, resumed or deleted (after commit). */
    public const SUBSCRIPTION_CHANGED = 'nowo_report_kit.subscription_changed';

    /** {@see ScheduledReportSentEvent}: after a scheduled mail was handed over and the run row committed. */
    public const SCHEDULED_REPORT_SENT = 'nowo_report_kit.scheduled_report_sent';

    /** {@see ScheduledReportPausedEvent}: a run paused its subscription (after commit). */
    public const SCHEDULED_REPORT_PAUSED = 'nowo_report_kit.scheduled_report_paused';

    /** {@see ScheduledReportFailedEvent}: a run failed before the mail was handed over (after commit). */
    public const SCHEDULED_REPORT_FAILED = 'nowo_report_kit.scheduled_report_failed';
}
