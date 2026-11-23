<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Console;

use Nowo\ReportKitBundle\Application\Run\RunRetention;
use Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * `nowo:report-kit:prune` (contract §9): deletes run rows older than `retention.run_days`, batch by batch, until none
 * is left. Subscriptions are never touched.
 */
#[AsCommand(name: 'nowo:report-kit:prune', description: 'Delete scheduled report run rows older than retention.run_days')]
final class PruneRunsCommand extends Command
{
    /**
     * Holds the retention service.
     */
    public function __construct(private readonly RunRetention $retention)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message = new PruneReportRunsMessage();
        $deleted = 0;
        do {
            $report = $this->retention->prune($message);
            $deleted += $report->deleted;
            $message = $report->remainder;
        } while ($message instanceof PruneReportRunsMessage);
        $output->writeln(sprintf('%d run row(s) deleted.', $deleted));

        return self::SUCCESS;
    }
}
