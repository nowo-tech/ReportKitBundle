<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Console;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * `nowo:report-kit:forget-owner <type> <id>` (audit ZAD-L2): erases the subscriptions, run rows and lock row of exactly
 * that owner, for hosts that delete or anonymise a person before the UserKit eraser of S4 exists. Exit 1 on an invalid
 * owner reference.
 */
#[AsCommand(name: 'nowo:report-kit:forget-owner', description: 'Erase the report subscriptions and run history of one owner')]
final class ForgetOwnerCommand extends Command
{
    /**
     * Holds the subscriptions service.
     */
    public function __construct(private readonly SubscriptionsInterface $subscriptions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::REQUIRED, 'Owner type (owner.type, e.g. user)')
            ->addArgument('id', InputArgument::REQUIRED, 'Owner id, exactly as stored');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getArgument('type');
        $id   = $input->getArgument('id');
        try {
            $owner = new OwnerRef(is_string($type) ? $type : '', is_string($id) ? $id : '');
        } catch (InvalidReportArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
        $counts = $this->subscriptions->forgetOwner($owner);
        $output->writeln(sprintf('%d subscription(s) and %d run row(s) erased.', $counts['subscriptions'], $counts['runs']));

        return self::SUCCESS;
    }
}
