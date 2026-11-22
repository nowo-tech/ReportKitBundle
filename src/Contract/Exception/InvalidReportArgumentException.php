<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Exception;

use InvalidArgumentException;

/**
 * A value object, a report section or a parameter schema was built with a value outside its rules. The message names
 * the field; it never echoes the value.
 */
final class InvalidReportArgumentException extends InvalidArgumentException implements ReportKitExceptionInterface
{
}
