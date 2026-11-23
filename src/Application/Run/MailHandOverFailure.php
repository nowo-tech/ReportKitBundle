<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Run;

use RuntimeException;
use Throwable;

/**
 * The mailer threw: the mail was not handed over (the run is retried with the error code `mail_failed`).
 *
 * @internal
 */
final class MailHandOverFailure extends RuntimeException
{
    /**
     * Wraps the mailer's failure.
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct('The scheduled report mail was not handed over.', 0, $previous);
    }
}
