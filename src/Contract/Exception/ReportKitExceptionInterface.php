<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Exception;

use Throwable;

/**
 * Marker of every exception the bundle throws on purpose: catch it to handle any report error at once.
 */
interface ReportKitExceptionInterface extends Throwable
{
}
