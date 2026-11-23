<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Messenger;

use LogicException;
use Nowo\ReportKitBundle\Application\Run\RunResult;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Handles {@see RunScheduledReportMessage} (`#[AsMessageHandler]`, REQ-MSG-001): one {@see ScheduledReportRunner}
 * run. Idempotent per slot: a redelivery after the hand-over sends nothing. A failure before the hand-over is
 * rethrown so the transport's retry strategy runs it again (default 3 retries, then the failure transport); the
 * failure that reaches `schedule.max_failures` pauses the subscription and is not rethrown. Running inside a
 * transaction (`doctrine_transaction` middleware) is a configuration error: unrecoverable, never retried. Stateless.
 */
#[AsMessageHandler]
final readonly class RunScheduledReportHandler
{
    /**
     * Holds the runner.
     */
    public function __construct(private ScheduledReportRunner $runner)
    {
    }

    /**
     * One run.
     */
    public function __invoke(RunScheduledReportMessage $message): RunResult
    {
        try {
            return $this->runner->run($message);
        } catch (LogicException $exception) {
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }
    }
}
