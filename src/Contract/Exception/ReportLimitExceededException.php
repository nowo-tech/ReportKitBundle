<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract\Exception;

use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;
use RuntimeException;

use function sprintf;

/**
 * A render went over one of the `limits.*` (REQ-RUNTIME-001): the time budget or the HTML size before Dompdf (nothing
 * is returned and every temporary file of the call is already removed), or a build got more scope contexts than one
 * build accepts.
 */
final class ReportLimitExceededException extends RuntimeException implements ReportKitExceptionInterface
{
    /** `limits.render_seconds` was exceeded. */
    public const RENDER_SECONDS = 'render_seconds';

    /** `limits.max_pdf_html_bytes` was exceeded. */
    public const PDF_HTML_BYTES = 'max_pdf_html_bytes';

    /** The scope resolver returned more than {@see ReportScopeResolverInterface::MAX_CONTEXTS} contexts. */
    public const SCOPE_CONTEXTS = 'scope_contexts';

    /**
     * @param string $limit one of the constants of this class
     * @param int $allowed the configured value of that limit
     */
    public function __construct(public readonly string $limit, public readonly int $allowed)
    {
        parent::__construct(sprintf('The report went over the limit "%s" (%d).', $limit, $allowed));
    }
}
