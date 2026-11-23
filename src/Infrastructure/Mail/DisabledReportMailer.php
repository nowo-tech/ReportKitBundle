<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Mail;

use LogicException;
use Nowo\ReportKitBundle\Contract\ReportMailerInterface;
use Nowo\ReportKitBundle\Contract\ScheduledReportMail;

/**
 * The mailer of `mail.enabled: false` (or `auto` without symfony/mailer): never ready, so every run skips with
 * `mail_not_ready` and keeps its schedule (Q-O9) until a mailer is configured.
 */
final readonly class DisabledReportMailer implements ReportMailerInterface
{
    /**
     * Never ready.
     */
    public function isReady(): bool
    {
        return false;
    }

    /**
     * Never called by the runner (it checks {@see self::isReady()} first).
     */
    public function send(ScheduledReportMail $mail): void
    {
        throw new LogicException('nowo_report_kit: mail is disabled (mail.enabled).');
    }
}
