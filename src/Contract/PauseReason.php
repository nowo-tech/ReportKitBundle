<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Why a subscription is paused (contract §4.1, §6.1). A run pauses with the reason instead of sending: the owner is no
 * longer active, lost access to the type or the subscribed context, the type was removed, or the runs failed
 * `schedule.max_failures` times in a row. `owner` / `operator` are manual pauses.
 */
enum PauseReason: string
{
    case Owner           = 'owner';
    case Operator        = 'operator';
    case OwnerInactive   = 'owner_inactive';
    case NoAccess        = 'no_access';
    case TypeRemoved     = 'type_removed';
    case RepeatedFailure = 'repeated_failure';
}
