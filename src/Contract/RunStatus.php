<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * The outcome of one scheduled run (contract §5.1 `last_status`, the run log): the mail was handed to the mailer,
 * the run was skipped (mail not ready, subscription paused or deleted meanwhile), it failed (retried by Messenger
 * until `schedule.max_failures`), or it paused the subscription with a {@see PauseReason}.
 */
enum RunStatus: string
{
    case Sent    = 'sent';
    case Skipped = 'skipped';
    case Failed  = 'failed';
    case Paused  = 'paused';
}
