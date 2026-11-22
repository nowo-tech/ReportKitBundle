<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Exception;

use Nowo\ReportKitBundle\Contract\ReportFormat;
use RuntimeException;

use function sprintf;

/**
 * The format is switched off (`formats.<format>.enabled`, the settings provider) or its engine is not installed.
 */
final class FormatNotEnabledException extends RuntimeException implements ReportKitExceptionInterface
{
    /**
     * @param ReportFormat $format the refused format
     */
    public function __construct(public readonly ReportFormat $format)
    {
        parent::__construct(sprintf('The report format "%s" is not enabled.', $format->value));
    }
}
