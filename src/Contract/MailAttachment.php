<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * One file attached to a scheduled mail: the rendered report in one format, in memory (bounded by
 * `mail.max_attachment_bytes` over all attachments).
 */
final readonly class MailAttachment
{
    /**
     * @param string $filename ASCII file name (`report-orders-2026-11-23.pdf`)
     * @param string $contentType the MIME type of the format
     * @param string $content the bytes
     */
    public function __construct(public string $filename, public string $contentType, public string $content)
    {
    }
}
