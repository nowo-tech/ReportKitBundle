<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Run;

use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\RunStatus;

/**
 * What one {@see ScheduledReportRunner::run()} did: the recorded status (null when the message was a duplicate or
 * pointed at nothing), the error / skip code and the pause reason.
 *
 * @internal
 */
final readonly class RunResult
{
    /** The slot was already handled or is being handled: nothing done. */
    public const DUPLICATE = 'duplicate';

    /** The message names no subscription (deleted, or a damaged message). */
    public const GONE = 'subscription_gone';

    /**
     * Holds the outcome.
     */
    public function __construct(public ?RunStatus $status, public ?string $code = null, public ?PauseReason $reason = null)
    {
    }
}
