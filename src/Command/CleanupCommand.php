<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Cleanup\CleanupResult;
use App\Application\Cleanup\CleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'bakedesk:cleanup', description: 'Remove retained BakeDesk print history, documents, and old orders.')]
final class CleanupCommand extends Command
{
    public function __construct(
        private readonly CleanupService $cleanupService,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview eligible records and documents without changing data.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('bakedesk.cleanup');
        try {
            if (!$lock->acquire()) {
                $output->writeln('<error>Another BakeDesk cleanup process is already running.</error>');

                return self::FAILURE;
            }
        } catch (LockConflictedException) {
            $output->writeln('<error>Another BakeDesk cleanup process is already running.</error>');

            return self::FAILURE;
        }

        try {
            $result = $this->cleanupService->run((bool) $input->getOption('dry-run'));
            $this->writeResult($output, $result);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln(sprintf('<error>BakeDesk cleanup failed: %s</error>', $exception->getMessage()));

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function writeResult(OutputInterface $output, CleanupResult $result): void
    {
        if ($result->dryRun) {
            $output->writeln('BakeDesk cleanup dry run');
            $output->writeln('');
            $output->writeln(sprintf('PrintJob cutoff: %s', $result->printJobCutoff->format(DATE_ATOM)));
            $output->writeln(sprintf('Terminal PrintJobs eligible: %d', $result->eligiblePrintJobs));
            $output->writeln(sprintf('Candidate document paths: %d', $result->candidateDocumentPaths));
            $output->writeln(sprintf('Unreferenced documents eligible: %d', $result->unreferencedDocumentPaths));
            $output->writeln(sprintf('Order cutoff: %s', $result->orderCutoff->format(DATE_ATOM)));
            $output->writeln(sprintf('Completed/cancelled Orders eligible: %d', $result->eligibleOrders));
            if ($result->missingDocuments > 0) {
                $output->writeln(sprintf('Candidate documents already missing: %d', $result->missingDocuments));
            }
            $output->writeln('Orphan diagnostic documents: preserved (no safe retention rule).');
            $output->writeln('');
            $output->writeln('No data was deleted (--dry-run).');

            return;
        }

        $output->writeln('BakeDesk cleanup');
        $output->writeln(sprintf('PrintJobs deleted: %d', $result->printJobsDeleted));
        $output->writeln(sprintf('Documents deleted: %d', $result->documentsDeleted));
        $output->writeln(sprintf('Orders deleted: %d', $result->ordersDeleted));
        if ($result->missingDocuments > 0) {
            $output->writeln(sprintf('Documents already missing: %d', $result->missingDocuments));
        }
        $output->writeln('Orphan diagnostic documents: preserved (no safe retention rule).');
    }
}
