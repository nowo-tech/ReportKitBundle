<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Message;

/**
 * Delete run rows older than `retention.run_days` in batches of `retention.batch_size` (contract §9); a pass that runs
 * out of `messenger.max_seconds` dispatches the rest as a new message. Scalars only.
 */
final readonly class PruneReportRunsMessage
{
    /**
     * @param string|null $after keyset cursor: continue after this run id (null = from the start)
     */
    public function __construct(public ?string $after = null)
    {
    }
}
