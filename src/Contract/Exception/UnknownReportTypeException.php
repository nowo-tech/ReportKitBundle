<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Exception;

use RuntimeException;

/**
 * The report type does not exist or is disabled (`types.disabled`): the pages answer 404.
 */
final class UnknownReportTypeException extends RuntimeException implements ReportKitExceptionInterface
{
    /**
     * @param string $type the requested key (not echoed in the message: it comes from the URL)
     */
    public function __construct(public readonly string $type)
    {
        parent::__construct('Unknown report type.');
    }
}
