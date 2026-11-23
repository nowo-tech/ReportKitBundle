<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Subscription;

use Closure;
use Nowo\ReportKitBundle\Contract\ReportMailerInterface;
use Nowo\ReportKitBundle\Contract\ScheduledReportMail;
use RuntimeException;

/**
 * Mailer double: records every handed-over mail; `$failures` makes the next sends throw (not handed over);
 * `$onSend` runs inside send (a test checks the locale there).
 */
final class RecordingMailer implements ReportMailerInterface
{
    /** @var list<ScheduledReportMail> */
    public array $sent = [];

    public bool $ready = true;

    public int $failures = 0;

    /** @var (Closure(ScheduledReportMail): void)|null */
    public ?Closure $onSend = null;

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function send(ScheduledReportMail $mail): void
    {
        if ($this->onSend instanceof Closure) {
            ($this->onSend)($mail);
        }
        if ($this->failures > 0) {
            --$this->failures;

            throw new RuntimeException('SMTP down');
        }
        $this->sent[] = $mail;
    }
}
