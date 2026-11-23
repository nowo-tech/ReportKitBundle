<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Run;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Nowo\ReportKitBundle\Application\Subscription\SubscriptionMapper;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\Event\ReportSubscriptionChangedEvent;
use Nowo\ReportKitBundle\Contract\Event\ScheduledReportFailedEvent;
use Nowo\ReportKitBundle\Contract\Event\ScheduledReportPausedEvent;
use Nowo\ReportKitBundle\Contract\Event\ScheduledReportSentEvent;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\ReportAccessDeniedException;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;
use Nowo\ReportKitBundle\Contract\MailAttachment;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Nowo\ReportKitBundle\Contract\OwnerDirectoryInterface;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\OwnerResolverInterface;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportBuilderInterface;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportMailerInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Contract\ScheduledReportMail;
use Nowo\ReportKitBundle\Contract\SubscriptionId;
use Nowo\ReportKitBundle\Domain\ReportRun;
use Nowo\ReportKitBundle\Domain\Subscription;
use Nowo\ReportKitBundle\Domain\SubscriptionStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function filter_var;
use function hrtime;
use function in_array;
use function intdiv;
use function is_string;
use function preg_match;
use function strlen;

use const FILTER_VALIDATE_EMAIL;

/**
 * Runs one claimed slot of one subscription (contract §6.1 scheduled path, §6.3, §9) **as the owner, at run time**:
 *
 * 1. **Take the slot.** Insert the run row of `(subscription, slot)` (unique), or take the lease of a row a failed or
 *    stale attempt left. Anything else (the slot was sent, is being sent, or another worker holds it) stops here:
 *    a duplicate claim or a Messenger redelivery never sends twice.
 * 2. **Check the owner now.** The subscription is still enabled; the type still exists; the directory still has an
 *    active user with an e-mail address whose owner reference is exactly the subscription's (REQ-SEC-006 rule 4).
 *    Otherwise the run pauses with `type_removed` / `owner_inactive`. An unready mailer skips (`mail_not_ready`) and
 *    keeps the schedule (Q-O9).
 * 3. **Build as the owner** through the same builder as the pages, in the owner's locale (`LocaleSwitcher`, restored
 *    even on failure): scope resolver, access checker and `ReportAccessCheckEvent` run again, so zero contexts on a
 *    real scope resolver are refused unless explicitly granted (audit ZY-M1). A refusal, or a subscribed context that
 *    is no longer among the accessible ones (the page would widen to "all accessible"; a run never does), pauses
 *    with `no_access`.
 * 4. **Render and hand over.** PDF (+ the chosen XLSX / CSV) within `mail.max_attachment_bytes` (above: link only);
 *    the run row goes to `sending` before the mailer is called, so after the hand-over nothing can send it again.
 * 5. **Record** in one transaction (subscription row locked `FOR UPDATE`): status, error code, failure count, pause,
 *    the claim cleared when it is still this run's. Events after the commit.
 *
 * A failure before the hand-over is recorded (`failed`, the lease released) and rethrown, so Messenger retries it;
 * at `schedule.max_failures` failures in a row the subscription pauses with `repeated_failure` and nothing is
 * rethrown. The runner refuses to run inside an open transaction (Messenger's `doctrine_transaction` middleware):
 * the mail would leave before the data is committed (REQ-RUNTIME-003 rule 7). Stateless (worker mode).
 */
final readonly class ScheduledReportRunner
{
    /** Error codes recorded on the run and the subscription. */
    public const MAIL_NOT_READY  = 'mail_not_ready';
    public const PAUSED_MEANTIME = 'paused';
    public const LIMIT_EXCEEDED  = 'limit_exceeded';
    public const RUN_FAILED      = 'run_failed';
    public const MAIL_FAILED     = 'mail_failed';

    /**
     * @param int $maxFailures `schedule.max_failures`
     * @param int $staleMinutes `schedule.stale_claim_minutes`: a run lease older than this may be taken over
     * @param int $maxAttachmentBytes `mail.max_attachment_bytes`
     */
    public function __construct(
        private SubscriptionStoreInterface $store,
        private ReportTypeRegistryInterface $types,
        private ReportBuilderInterface $builder,
        private ReportExporterInterface $exporter,
        private OwnerDirectoryInterface $directory,
        private OwnerResolverInterface $owners,
        private ReportMailerInterface $mailer,
        private ReportLinksInterface $links,
        private ClockInterface $clock,
        private int $maxFailures = 3,
        private int $staleMinutes = 30,
        private int $maxAttachmentBytes = 8388608,
        private ?LocaleSwitcher $localeSwitcher = null,
        private ?EventDispatcherInterface $events = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Runs the slot of the message.
     *
     * @throws LogicException inside an open transaction
     * @throws Throwable a failure before the hand-over (recorded; Messenger retries)
     */
    public function run(RunScheduledReportMessage $message): RunResult
    {
        if ($this->store->isTransactionActive()) {
            throw new LogicException('nowo_report_kit: RunScheduledReportMessage must not run inside a database transaction (remove the doctrine_transaction middleware from the bus that handles it): the mail would leave before the commit.');
        }
        $logger = $this->logger ?? new NullLogger();
        try {
            $id   = new SubscriptionId($message->subscriptionId);
            $slot = self::slot($message->slotAt);
        } catch (InvalidReportArgumentException) {
            $logger->warning('nowo_report_kit: ignored a damaged RunScheduledReportMessage.');

            return new RunResult(null, RunResult::GONE);
        }
        $row = $this->store->find($id->value);
        if (!$row instanceof Subscription) {
            $logger->info('nowo_report_kit: subscription {id} is gone; slot {slot} not run.', ['id' => $id->value, 'slot' => $message->slotAt]);

            return new RunResult(null, RunResult::GONE);
        }
        $started = hrtime(true);
        $now     = $this->clock->now();
        $stale   = $now->modify('-' . $this->staleMinutes . ' minutes');
        $taken   = $this->store->transactional(fn (): bool => $this->store->insertRun(new ReportRun((string) Uuid::v7(), $row->id, $row->ownerType, $row->ownerId, $slot, ReportRun::RUNNING, [], 0, 0, null, 1, $now, $now))
            || $this->store->takeRun($row->id, $slot, $now, $stale));
        if (!$taken) {
            $logger->info('nowo_report_kit: slot {slot} of subscription {id} is already handled; nothing sent.', ['id' => $row->id, 'slot' => $message->slotAt]);

            return new RunResult(null, RunResult::DUPLICATE);
        }

        $handedOver = false;
        try {
            return $this->attempt($row, $slot, $message, $started, $handedOver);
        } catch (Throwable $failure) {
            if ($handedOver) {
                // The mail left: never mark the slot as retryable. A redelivery finds it `sending` and stops.
                $logger->error('nowo_report_kit: subscription {id} slot {slot} was handed to the mailer but could not be recorded: {class}', ['id' => $row->id, 'slot' => $message->slotAt, 'class' => $failure::class]);

                throw $failure;
            }

            return $this->failed($row, $slot, $message, $failure, $started);
        }
    }

    /**
     * Steps 2 to 5; `$handedOver` turns true once the mailer returned.
     */
    private function attempt(Subscription $row, DateTimeImmutable $slot, RunScheduledReportMessage $message, int $started, bool &$handedOver): RunResult
    {
        if (!$row->enabled) {
            return $this->finish($row, $slot, $message, RunStatus::Skipped, self::PAUSED_MEANTIME, null, [], 0, $started);
        }
        try {
            $owner = SubscriptionMapper::owner($row);
        } catch (InvalidReportArgumentException) {
            return $this->finish($row, $slot, $message, RunStatus::Paused, PauseReason::OwnerInactive->value, PauseReason::OwnerInactive, [], 0, $started);
        }
        if ($this->types->get($row->reportType) === null) {
            return $this->pause($row, $slot, $message, PauseReason::TypeRemoved, $started);
        }
        $user  = $this->directory->userOf($owner);
        $email = $user === null ? null : $this->directory->email($owner);
        if ($user === null || !$this->directory->isActive($owner) || !$this->sameOwner($owner, $user) || !is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->pause($row, $slot, $message, PauseReason::OwnerInactive, $started);
        }
        if (!$this->mailer->isReady()) {
            return $this->finish($row, $slot, $message, RunStatus::Skipped, self::MAIL_NOT_READY, null, [], 0, $started);
        }
        $parameters = SubscriptionMapper::parameters($row);
        $locale     = self::locale($this->directory->locale($owner));
        $timezone   = $this->directory->timezone($owner);

        $built = $this->inLocale($locale, function () use ($row, $owner, $user, $parameters, $locale, $timezone, $email, $slot): ScheduledReportMail|PauseReason {
            try {
                $prepared = $this->builder->prepare($row->reportType, $owner, $user, SubscriptionMapper::raw($parameters), true, $locale, $timezone);
            } catch (ReportAccessDeniedException) {
                return PauseReason::NoAccess;
            } catch (UnknownReportTypeException) {
                return PauseReason::TypeRemoved;
            }
            $effective = $prepared->request->parameters->context;
            if ($parameters->context instanceof ContextRef && (!$effective instanceof ContextRef || !$effective->equals($parameters->context))) {
                // The subscribed context is no longer accessible: never widen to "all accessible".
                return PauseReason::NoAccess;
            }
            $report = $prepared->type->build($prepared->request);

            return $this->mail($row, $owner, $email, $locale, $report, SubscriptionMapper::raw($prepared->request->parameters), $slot);
        });
        if ($built instanceof PauseReason) {
            return $this->pause($row, $slot, $message, $built, $started);
        }

        $sending = $this->store->transactional(fn (): bool => $this->store->updateRun($row->id, $slot, ['status' => ReportRun::SENDING], ReportRun::RUNNING));
        if (!$sending) {
            // Another worker took the lease over meanwhile (this attempt outlived schedule.stale_claim_minutes).
            return new RunResult(null, RunResult::DUPLICATE);
        }
        try {
            $this->inLocale($locale, function () use ($built): void {
                $this->mailer->send($built);
            });
        } catch (Throwable $failure) {
            throw new MailHandOverFailure($failure);
        }
        $handedOver = true;
        $formats    = [];
        $bytes      = 0;
        foreach ($built->attachments as $attachment) {
            $bytes += strlen($attachment->content);
        }
        foreach ($this->attachedFormats($row) as $format) {
            if (!$built->attachmentsDropped) {
                $formats[] = $format->value;
            }
        }

        return $this->finish($row, $slot, $message, RunStatus::Sent, null, null, $formats, $bytes, $started);
    }

    /**
     * @param array<string, bool|int|string|null> $query
     */
    private function mail(Subscription $row, OwnerRef $owner, string $email, string $locale, Report $report, array $query, DateTimeImmutable $slot): ScheduledReportMail
    {
        $attachments = [];
        $total       = 0;
        $dropped     = false;
        foreach ($this->attachedFormats($row) as $format) {
            $file = $this->exporter->export($report, $format);
            $total += $file->size;
            if ($total > $this->maxAttachmentBytes) {
                $dropped     = true;
                $attachments = [];

                break;
            }
            $attachments[] = new MailAttachment($file->filename, $file->contentType, $file->contents());
        }

        return new ScheduledReportMail(
            new SubscriptionId($row->id),
            $owner,
            $email,
            $locale,
            $this->directory->displayName($owner),
            $report,
            $attachments,
            $this->links->reportUrl($row->reportType, $query),
            $this->links->subscriptionsUrl(),
            $dropped,
            $slot,
        );
    }

    /**
     * The stored formats that are enabled right now.
     *
     * @return list<ReportFormat>
     */
    private function attachedFormats(Subscription $row): array
    {
        $enabled = $this->exporter->formats();
        $formats = [];
        foreach (SubscriptionMapper::formats($row) as $format) {
            if (in_array($format, $enabled, true)) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    private function sameOwner(OwnerRef $owner, object $user): bool
    {
        $resolved = $this->owners->ownerOf($user);
        if ($resolved instanceof OwnerRef && $resolved->equals($owner)) {
            return true;
        }
        ($this->logger ?? new NullLogger())->warning('nowo_report_kit: the owner directory returned a user of another owner for {type}; the run is paused (REQ-SEC-006).', ['type' => $owner->type]);

        return false;
    }

    private function pause(Subscription $row, DateTimeImmutable $slot, RunScheduledReportMessage $message, PauseReason $reason, int $started): RunResult
    {
        return $this->finish($row, $slot, $message, RunStatus::Paused, $reason->value, $reason, [], 0, $started);
    }

    /**
     * Records the outcome: the run row and the subscription in one transaction; events after the commit.
     *
     * @param list<string> $formats
     */
    private function finish(Subscription $row, DateTimeImmutable $slot, RunScheduledReportMessage $message, RunStatus $status, ?string $code, ?PauseReason $reason, array $formats, int $bytes, int $started): RunResult
    {
        $now    = $this->clock->now();
        $paused = $this->store->transactional(function () use ($row, $slot, $message, $status, $code, $reason, $formats, $bytes, $started, $now): bool {
            $current = $this->store->find($row->id, true);
            $paused  = false;
            if ($current instanceof Subscription) {
                $values = ['last_run_at' => $now, 'last_status' => $status->value, 'last_error_code' => $code];
                if ($status === RunStatus::Sent) {
                    $values['consecutive_failures'] = 0;
                }
                if ($reason instanceof PauseReason && $current->enabled) {
                    $values['enabled']      = false;
                    $values['pause_reason'] = $reason->value;
                    $paused                 = true;
                }
                $this->store->update($current->id, $values + $this->releaseClaim($current, $message), $now);
            }
            $this->store->updateRun($row->id, $slot, ['status' => $status->value, 'formats' => $formats, 'bytes' => $bytes, 'duration_ms' => self::ms($started), 'error_code' => $code, 'lease_at' => null]);

            return $paused;
        }, function (bool $paused) use ($row, $slot, $status, $reason, $formats, $bytes): void {
            $owner = new OwnerRef($row->ownerType, $row->ownerId);
            $id    = new SubscriptionId($row->id);
            if ($status === RunStatus::Sent) {
                $list = [];
                foreach ($formats as $format) {
                    $list[] = ReportFormat::from($format);
                }
                $this->events?->dispatch(new ScheduledReportSentEvent($id, $owner, $slot, $list, $bytes), ReportKitEvents::SCHEDULED_REPORT_SENT);
            }
            if ($paused && $reason instanceof PauseReason) {
                $this->pausedEvents($id, $owner, $row->reportType, $reason);
            }
        });
        ($this->logger ?? new NullLogger())->info('nowo_report_kit: subscription {id} slot {slot}: {status}{code}.', ['id' => $row->id, 'slot' => $message->slotAt, 'status' => $status->value, 'code' => $code === null ? '' : ' (' . $code . ')']);

        return new RunResult($status, $code, $reason);
    }

    /**
     * A failure before the hand-over: counted, recorded with the lease released, rethrown for a Messenger retry;
     * the failure that reaches `schedule.max_failures` pauses the subscription and is not rethrown.
     *
     * @throws Throwable
     */
    private function failed(Subscription $row, DateTimeImmutable $slot, RunScheduledReportMessage $message, Throwable $failure, int $started): RunResult
    {
        $cause = $failure instanceof MailHandOverFailure ? $failure->getPrevious() ?? $failure : $failure;
        $code  = match (true) {
            $failure instanceof MailHandOverFailure          => self::MAIL_FAILED,
            $failure instanceof ReportLimitExceededException => self::LIMIT_EXCEEDED,
            default                                          => self::RUN_FAILED,
        };
        $now     = $this->clock->now();
        $outcome = $this->store->transactional(function () use ($row, $slot, $message, $code, $started, $now): array {
            $current  = $this->store->find($row->id, true);
            $failures = ($current instanceof Subscription ? $current->consecutiveFailures : $row->consecutiveFailures) + 1;
            $pause    = $current instanceof Subscription && $current->enabled && $failures >= $this->maxFailures;
            if ($current instanceof Subscription) {
                $values = ['last_run_at' => $now, 'last_status' => ($pause ? RunStatus::Paused : RunStatus::Failed)->value, 'last_error_code' => $code, 'consecutive_failures' => $failures];
                if ($pause) {
                    $values['enabled']      = false;
                    $values['pause_reason'] = PauseReason::RepeatedFailure->value;
                }
                $this->store->update($current->id, $values + $this->releaseClaim($current, $message), $now);
            }
            $this->store->updateRun($row->id, $slot, ['status' => ($pause ? RunStatus::Paused : RunStatus::Failed)->value, 'duration_ms' => self::ms($started), 'error_code' => $code, 'lease_at' => null]);

            return [$failures, $pause];
        }, function (array $outcome) use ($row, $slot, $code): void {
            [$failures, $pause] = $outcome;
            $id                 = new SubscriptionId($row->id);
            $this->events?->dispatch(new ScheduledReportFailedEvent($id, $slot, $code, $failures), ReportKitEvents::SCHEDULED_REPORT_FAILED);
            if ($pause) {
                $this->pausedEvents($id, new OwnerRef($row->ownerType, $row->ownerId), $row->reportType, PauseReason::RepeatedFailure);
            }
        });
        [$failures, $pause] = $outcome;
        ($this->logger ?? new NullLogger())->error('nowo_report_kit: subscription {id} slot {slot} failed ({code}, {failures} in a row): {class}', [
            'id'        => $row->id,
            'slot'      => $message->slotAt,
            'code'      => $code,
            'failures'  => $failures,
            'class'     => $cause::class,
            'exception' => $cause,
        ]);
        if ($pause) {
            return new RunResult(RunStatus::Paused, $code, PauseReason::RepeatedFailure);
        }

        throw $cause;
    }

    private function pausedEvents(SubscriptionId $id, OwnerRef $owner, string $type, PauseReason $reason): void
    {
        $this->events?->dispatch(new ScheduledReportPausedEvent($id, $owner, $reason), ReportKitEvents::SCHEDULED_REPORT_PAUSED);
        $this->events?->dispatch(new ReportSubscriptionChangedEvent($id, $owner, $type, ReportSubscriptionChangedEvent::PAUSED, null, $reason), ReportKitEvents::SUBSCRIPTION_CHANGED);
    }

    /**
     * Clears the claim when it is still this run's (a stale re-claim by another dispatcher keeps its own).
     *
     * @return array<string, null>
     */
    private function releaseClaim(Subscription $current, RunScheduledReportMessage $message): array
    {
        return $current->claimToken === $message->claimToken ? ['claim_token' => null, 'claimed_at' => null] : [];
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function inLocale(string $locale, Closure $work): mixed
    {
        return $this->localeSwitcher instanceof LocaleSwitcher ? $this->localeSwitcher->runWithLocale($locale, $work) : $work();
    }

    private static function locale(?string $locale): string
    {
        return is_string($locale) && preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/D', $locale) === 1 ? $locale : 'en';
    }

    /**
     * @throws InvalidReportArgumentException
     */
    private static function slot(string $value): DateTimeImmutable
    {
        $slot = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'))
            : false;
        if ($slot === false) {
            throw new InvalidReportArgumentException('RunScheduledReportMessage slotAt must be a UTC time.');
        }

        return $slot;
    }

    private static function ms(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}
