<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Run;

use DateTimeZone;
use Nowo\ReportKitBundle\Application\Identity\RandomIdGenerator;
use Nowo\ReportKitBundle\Application\Subscription\SubscriptionMapper;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;
use Nowo\ReportKitBundle\Domain\IdGeneratorInterface;
use Nowo\ReportKitBundle\Domain\SubscriptionStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;

/**
 * The claim pass of {@see DispatchDueReportsMessage} (contract §9): reads at most `schedule.batch` due rows (keyset by
 * id) and claims each with one conditional UPDATE ({@see SubscriptionStoreInterface::claim()}), which moves
 * `next_run_at` to the first slot after now. Two dispatchers racing on one row: exactly one UPDATE matches, the other
 * claims nothing. A claim that is still running blocks a new claim until it is older than
 * `schedule.stale_claim_minutes` (a crashed worker's claim expires). Missed slots (the scheduler was down) run once,
 * not once per missed slot. Stops after `messenger.max_seconds` and hands back the rest.
 *
 * Nothing is built or sent here; with `schedulesEnabled()` false nothing is claimed. Stateless.
 */
final readonly class DueReportsDispatcher
{
    /**
     * @param int $batch `schedule.batch`
     * @param int $staleClaimMinutes `schedule.stale_claim_minutes`
     * @param int $maxSeconds `messenger.max_seconds`
     */
    public function __construct(
        private SubscriptionStoreInterface $store,
        private ReportSettingsProviderInterface $settings,
        private ClockInterface $clock,
        private int $batch = 200,
        private int $staleClaimMinutes = 30,
        private int $maxSeconds = 50,
        private ?LoggerInterface $logger = null,
        private ?IdGeneratorInterface $ids = null,
    ) {
    }

    /**
     * One bounded pass.
     */
    public function claim(DispatchDueReportsMessage $message): DispatchPass
    {
        $logger = $this->logger ?? new NullLogger();
        if (!$this->settings->schedulesEnabled()) {
            $logger->info('nowo_report_kit: scheduled runs are off (schedulesEnabled() is false); nothing claimed.');

            return new DispatchPass([], null, 0);
        }
        $start    = $this->clock->now();
        $deadline = $start->getTimestamp() + $this->maxSeconds;
        $rows     = $this->store->due($start, $this->batch, $message->after);
        $runs     = [];
        $last     = null;
        $scanned  = 0;
        foreach ($rows as $row) {
            $now = $this->clock->now();
            if ($now->getTimestamp() >= $deadline) {
                return new DispatchPass($runs, new DispatchDueReportsMessage($last), $scanned);
            }
            $last = $row->id;
            ++$scanned;
            try {
                $next = SubscriptionMapper::schedule($row)->next($now);
            } catch (InvalidReportArgumentException) {
                $logger->warning('nowo_report_kit: subscription {id} has a damaged schedule; not claimed.', ['id' => $row->id]);

                continue;
            }
            $token = $this->ids()->next();
            $stale = $now->modify('-' . $this->staleClaimMinutes . ' minutes');
            $won   = $this->store->transactional(fn (): bool => $this->store->claim($row->id, $row->nextRunAt, $next, $token, $now, $stale));
            if ($won) {
                $runs[] = new RunScheduledReportMessage($row->id, $row->nextRunAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), $token);
            }
        }
        $logger->info('nowo_report_kit: claimed {claimed} of {due} due subscription(s).', ['claimed' => count($runs), 'due' => count($rows)]);

        // A full batch may have more due rows after it.
        return new DispatchPass($runs, count($rows) >= $this->batch ? new DispatchDueReportsMessage($last) : null, $scanned);
    }

    /**
     * The id generator (the container wires the symfony/uid UUIDv7 one; a hand-built service falls back to
     * {@see RandomIdGenerator}).
     */
    private function ids(): IdGeneratorInterface
    {
        return $this->ids ?? new RandomIdGenerator();
    }
}
