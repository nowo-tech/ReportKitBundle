<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Messenger;

use Nowo\ReportKitBundle\Application\Run\PruneReport;
use Nowo\ReportKitBundle\Application\Run\RunRetention;
use Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles {@see PruneReportRunsMessage} (`#[AsMessageHandler]`, REQ-MSG-001): one bounded retention pass, the rest
 * dispatched as a new message (keyset cursor). Idempotent: a redelivery deletes what is still old. Stateless.
 */
#[AsMessageHandler]
final readonly class PruneReportRunsHandler
{
    /**
     * Holds the retention service and the bus.
     */
    public function __construct(private RunRetention $retention, private ?MessageBusInterface $bus = null, private ?LoggerInterface $logger = null)
    {
    }

    /**
     * One pass.
     */
    public function __invoke(PruneReportRunsMessage $message): PruneReport
    {
        $report  = $this->retention->prune($message);
        $deleted = $report->deleted;
        while ($report->remainder instanceof PruneReportRunsMessage && !$this->bus instanceof MessageBusInterface) {
            $report = $this->retention->prune($report->remainder);
            $deleted += $report->deleted;
        }
        if ($report->remainder instanceof PruneReportRunsMessage) {
            $this->bus?->dispatch($report->remainder);
        }
        $report = new PruneReport($deleted, $report->remainder);
        ($this->logger ?? new NullLogger())->info('nowo_report_kit: retention deleted {deleted} run row(s).', ['deleted' => $report->deleted, 'more' => $report->remainder instanceof PruneReportRunsMessage]);

        return $report;
    }
}
