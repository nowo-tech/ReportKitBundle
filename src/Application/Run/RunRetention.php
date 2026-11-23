<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Run;

use Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage;
use Nowo\ReportKitBundle\Domain\SubscriptionStoreInterface;
use Psr\Clock\ClockInterface;

use function count;
use function end;

/**
 * Retention of the run history (contract §5.1, §9): deletes run rows created more than `retention.run_days` ago, in
 * batches of `retention.batch_size`, one short transaction per batch (REQ-RUNTIME-003 rule 9), keyset by id, until
 * `messenger.max_seconds` are spent. Subscriptions are never touched. Stateless.
 */
final readonly class RunRetention
{
    /**
     * @param int $runDays `retention.run_days`
     * @param int $batchSize `retention.batch_size`
     * @param int $maxSeconds `messenger.max_seconds`
     */
    public function __construct(
        private SubscriptionStoreInterface $store,
        private ClockInterface $clock,
        private int $runDays = 90,
        private int $batchSize = 500,
        private int $maxSeconds = 50,
    ) {
    }

    /**
     * One bounded pass.
     */
    public function prune(PruneReportRunsMessage $message): PruneReport
    {
        $start    = $this->clock->now();
        $before   = $start->modify('-' . $this->runDays . ' days');
        $deadline = $start->getTimestamp() + $this->maxSeconds;
        $after    = $message->after;
        $deleted  = 0;
        while (true) {
            $ids = $this->store->oldRuns($before, $this->batchSize, $after);
            if ($ids === []) {
                return new PruneReport($deleted, null);
            }
            $deleted += $this->store->transactional(fn (): int => $this->store->deleteRuns($ids));
            $after = end($ids);
            if (count($ids) < $this->batchSize) {
                return new PruneReport($deleted, null);
            }
            if ($this->clock->now()->getTimestamp() >= $deadline) {
                return new PruneReport($deleted, new PruneReportRunsMessage($after));
            }
        }
    }
}
