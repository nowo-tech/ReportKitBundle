<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;

use function mb_strlen;
use function sprintf;

/**
 * One context a viewer may report on, with its display name (contract §4.3): what
 * {@see ReportScopeResolverInterface::accessibleContexts()} returns. The label is shown in the filter form, the
 * filters line and the file name; it is cleaned like every report text ({@see Guard::text()}).
 */
final readonly class ContextChoice
{
    /** Longest label, in characters. */
    public const MAX_LABEL_LENGTH = 200;

    /** Display name, cleaned. */
    public string $label;

    /**
     * @param ContextRef $ref the context
     * @param string $label its display name (control characters become spaces; 1 to 200 characters after cleaning)
     */
    public function __construct(public ContextRef $ref, string $label)
    {
        $clean = Guard::text($label);
        if ($clean === '' || mb_strlen($clean, 'UTF-8') > self::MAX_LABEL_LENGTH) {
            throw new InvalidReportArgumentException(sprintf('ContextChoice label must be 1 to %d characters.', self::MAX_LABEL_LENGTH));
        }
        $this->label = $clean;
    }
}
