<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Messenger;

use Nowo\ReportKitBundle\Application\Run\DispatchPass;
use Nowo\ReportKitBundle\Application\Run\DueReportsDispatcher;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage;
use Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Handles {@see DispatchDueReportsMessage} (`#[AsMessageHandler]`, REQ-MSG-001, contract §9): one bounded claim pass,
 * then one {@see RunScheduledReportMessage} per claimed slot on the bus (route
 * it to the maintenance transport) and the rest of the pass as a new dispatch message; without a bus the claimed slots
 * run here, one after the other. Only enqueues
 * (REQ-MESSENGER-008); a redelivery claims nothing twice (the claim is a conditional UPDATE). Stateless.
 */
#[AsMessageHandler]
final readonly class DispatchDueReportsHandler
{
    /**
     * Holds the dispatcher and the bus.
     */
    public function __construct(private DueReportsDispatcher $dispatcher, private ScheduledReportRunner $runner, private ?MessageBusInterface $bus = null)
    {
    }

    /**
     * One pass.
     */
    public function __invoke(DispatchDueReportsMessage $message): DispatchPass
    {
        $pass = $this->dispatcher->claim($message);
        foreach ($pass->runs as $run) {
            if ($this->bus instanceof MessageBusInterface) {
                $this->bus->dispatch($run);

                continue;
            }
            try {
                $this->runner->run($run);
            } catch (Throwable) {
                // Recorded by the runner (failed, counted); the other claimed slots still run.
            }
        }
        if ($pass->remainder instanceof DispatchDueReportsMessage) {
            $this->bus?->dispatch($pass->remainder);
        }

        return $pass;
    }
}
