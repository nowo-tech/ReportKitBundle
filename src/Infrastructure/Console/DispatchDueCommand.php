<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Console;

use Nowo\ReportKitBundle\Application\Run\DueReportsDispatcher;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function sprintf;

/**
 * `nowo:report-kit:dispatch-due` (contract §9): claims the due subscriptions and enqueues one run message per claimed
 * slot, for hosts with their own schedule (Beacon: `5 * * * *`). Without Messenger, or with `--sync`, the claimed
 * runs execute in this process one after the other (small installs only: one slow report delays the rest). A failed
 * run is reported and counted; the others still run. Exit 1 when a run failed.
 */
#[AsCommand(name: 'nowo:report-kit:dispatch-due', description: 'Claim the due report subscriptions and run or enqueue them')]
final class DispatchDueCommand extends Command
{
    /**
     * Holds the dispatcher, the runner and the bus (null = no Messenger).
     */
    public function __construct(private readonly DueReportsDispatcher $dispatcher, private readonly ScheduledReportRunner $runner, private readonly ?MessageBusInterface $bus = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sync', null, InputOption::VALUE_NONE, 'Run the claimed slots in this process instead of enqueueing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sync    = $input->getOption('sync') === true || !$this->bus instanceof MessageBusInterface;
        $message = new DispatchDueReportsMessage();
        $claimed = 0;
        $failed  = 0;
        do {
            $pass = $this->dispatcher->claim($message);
            foreach ($pass->runs as $run) {
                ++$claimed;
                if (!$sync && $this->bus instanceof MessageBusInterface) {
                    $this->bus->dispatch($run);

                    continue;
                }
                try {
                    $result = $this->runner->run($run);
                    $output->writeln(sprintf('%s %s: %s', $run->subscriptionId, $run->slotAt, $result->status->value ?? (string) $result->code), OutputInterface::VERBOSITY_VERBOSE);
                } catch (Throwable $failure) {
                    ++$failed;
                    $output->writeln(sprintf('<error>%s %s failed: %s</error>', $run->subscriptionId, $run->slotAt, $failure::class));
                }
            }
            $message = $pass->remainder;
        } while ($message instanceof DispatchDueReportsMessage);
        $output->writeln(sprintf('%d slot(s) claimed, %s.', $claimed, $sync ? sprintf('run in this process, %d failed', $failed) : 'enqueued'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
