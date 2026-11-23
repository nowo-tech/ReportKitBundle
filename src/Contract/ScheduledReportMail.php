<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeImmutable;

/**
 * A scheduled report mail (contract §6.3), built by the runner for {@see ReportMailerInterface}: sent **only** to the
 * owner's current address from the directory, in the owner's locale, with absolute links built from
 * `links.base_url` / the router's `default_uri` (never a request Host).
 *
 * `$attachments` is empty when they would exceed `mail.max_attachment_bytes`; `$attachmentsDropped` then tells the
 * template to say "open the link" (the page needs a sign-in).
 */
final readonly class ScheduledReportMail
{
    /**
     * @param string $to the owner's current e-mail address
     * @param string $locale the owner's locale
     * @param string|null $recipientName the owner's display name
     * @param Report $report the report as built for the owner (title, filters line, summary, glance, brand context)
     * @param list<MailAttachment> $attachments the files
     * @param string|null $reportUrl absolute URL of the report page with the subscription's parameters (null = no pages)
     * @param string|null $subscriptionsUrl absolute URL of the owner's subscriptions list (pause / delete)
     * @param bool $attachmentsDropped the files were too large and are not attached
     * @param DateTimeImmutable $slotAt the scheduled slot this mail is for, UTC
     */
    public function __construct(
        public SubscriptionId $subscriptionId,
        public OwnerRef $owner,
        public string $to,
        public string $locale,
        public ?string $recipientName,
        public Report $report,
        public array $attachments,
        public ?string $reportUrl,
        public ?string $subscriptionsUrl,
        public bool $attachmentsDropped,
        public DateTimeImmutable $slotAt,
    ) {
    }
}
