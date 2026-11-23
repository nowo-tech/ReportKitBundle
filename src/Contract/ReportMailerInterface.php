<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Throwable;

/**
 * Host SPI (contract §4.3, §6.3): sends a scheduled report mail. The default renders `mail.template` and sends it
 * through Symfony Mailer (branded through MailKit's `BrandedEmailHelper` when installed); a host plugs its own
 * (`mail.mailer`).
 *
 * `send()` returning means the mail was **handed over** (to the transport or the host's mail queue): the run is
 * `sent` from then on and is never sent again, even if the run fails afterwards. An exception means it was not
 * handed over: the run fails and Messenger retries it.
 */
interface ReportMailerInterface
{
    /**
     * Whether mail can be sent right now (MailKit's `OutboundMailReadinessInterface` when installed). False skips the
     * run with `mail_not_ready` and keeps the schedule (Q-O9).
     */
    public function isReady(): bool;

    /**
     * Hands the mail over.
     *
     * @throws Throwable when the mail could not be handed over (the run is retried)
     */
    public function send(ScheduledReportMail $mail): void;
}
