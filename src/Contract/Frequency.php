<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * How often a subscription runs (contract §4.1): every day, every week on one weekday, every month on one day (1–28).
 */
enum Frequency: string
{
    case Daily   = 'daily';
    case Weekly  = 'weekly';
    case Monthly = 'monthly';
}
