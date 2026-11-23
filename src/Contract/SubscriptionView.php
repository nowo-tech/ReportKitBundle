<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeImmutable;

/**
 * One subscription as its owner sees it (contract §4.1): what runs, when, in which formats, and how the last run
 * went. Ids and scalars only; no report content, no e-mail address.
 *
 * `$version` changes with every write to the row (owner toggles, runs, operator pauses): the pages post it back so a
 * pause or delete decided on a stale view answers 409 instead of undoing a newer change (REQ-RUNTIME-003).
 */
final readonly class SubscriptionView
{
    /**
     * @param string $type the report type key
     * @param ReportParameters $parameters the stored parameters (period, context, extras)
     * @param list<ReportFormat> $formats the formats attached to the mail (PDF always first when it is enabled, Q-O7)
     * @param PauseReason|null $pauseReason null while enabled
     * @param DateTimeImmutable $nextRunAt the next slot, UTC
     * @param DateTimeImmutable|null $lastRunAt when the last run finished, UTC
     * @param RunStatus|null $lastStatus the outcome of the last run
     * @param string|null $lastErrorCode a code of the last failure or skip (e.g. `mail_not_ready`), never a message
     * @param int $consecutiveFailures failed runs in a row (auto-pause at `schedule.max_failures`)
     * @param int $version optimistic version of the row
     */
    public function __construct(
        public SubscriptionId $id,
        public OwnerRef $owner,
        public string $type,
        public ReportParameters $parameters,
        public Schedule $schedule,
        public array $formats,
        public bool $enabled,
        public ?PauseReason $pauseReason,
        public DateTimeImmutable $nextRunAt,
        public ?DateTimeImmutable $lastRunAt,
        public ?RunStatus $lastStatus,
        public ?string $lastErrorCode = null,
        public int $consecutiveFailures = 0,
        public int $version = 1,
        public ?DateTimeImmutable $createdAt = null,
    ) {
    }
}
