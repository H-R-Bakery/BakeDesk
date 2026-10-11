<?php

declare(strict_types=1);

namespace App\Application\Cleanup;

use App\Application\Order\BakeryClock;
use App\Repository\OrderRepository;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class CleanupService
{
    private const BATCH_SIZE = 250;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrintJobRepository $printJobRepository,
        private readonly OrderRepository $orderRepository,
        private readonly CleanupRetention $retention,
        private readonly BakeryClock $bakeryClock,
        #[Target('documents.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public function run(bool $dryRun = false): CleanupResult
    {
        $now = $this->bakeryClock->now()->setTimezone(new \DateTimeZone('UTC'));
        $printJobCutoff = $this->retention->printJobCutoff($now);
        $orderCutoff = $this->retention->orderCutoff($now);
        $eligiblePrintJobs = $this->printJobRepository->countEligibleForCleanup($printJobCutoff);

        if ($dryRun) {
            [$candidateDocumentPaths, $unreferencedDocumentPaths, $missingDocuments] = $this->previewDocuments($printJobCutoff);
            $eligibleOrders = $this->orderRepository->countEligibleForCleanup($orderCutoff, $printJobCutoff);

            return new CleanupResult(
                dryRun: true,
                printJobCutoff: $printJobCutoff,
                orderCutoff: $orderCutoff,
                eligiblePrintJobs: $eligiblePrintJobs,
                printJobsDeleted: 0,
                candidateDocumentPaths: $candidateDocumentPaths,
                unreferencedDocumentPaths: $unreferencedDocumentPaths,
                documentsDeleted: 0,
                missingDocuments: $missingDocuments,
                eligibleOrders: $eligibleOrders,
                ordersDeleted: 0,
            );
        }

        [$printJobsDeleted, $candidateDocumentPaths, $unreferencedDocumentPaths, $documentsDeleted, $missingDocuments] = $this->deletePrintJobsAndDocuments($printJobCutoff);
        $eligibleOrders = $this->orderRepository->countEligibleForCleanup($orderCutoff);
        $ordersDeleted = $this->deleteOrders($orderCutoff);

        return new CleanupResult(
            dryRun: false,
            printJobCutoff: $printJobCutoff,
            orderCutoff: $orderCutoff,
            eligiblePrintJobs: $eligiblePrintJobs,
            printJobsDeleted: $printJobsDeleted,
            candidateDocumentPaths: $candidateDocumentPaths,
            unreferencedDocumentPaths: $unreferencedDocumentPaths,
            documentsDeleted: $documentsDeleted,
            missingDocuments: $missingDocuments,
            eligibleOrders: $eligibleOrders,
            ordersDeleted: $ordersDeleted,
        );
    }

    /**
     * @return array{int, int, int}
     */
    private function previewDocuments(\DateTimeImmutable $cutoff): array
    {
        $candidateDocumentPaths = 0;
        $unreferencedDocumentPaths = 0;
        $missingDocuments = 0;
        $offset = 0;

        do {
            $paths = $this->printJobRepository->findEligibleDocumentPaths($cutoff, self::BATCH_SIZE, $offset);
            $offset += count($paths);

            foreach ($paths as $path) {
                ++$candidateDocumentPaths;
                if ($this->printJobRepository->hasRemainingDocumentReferenceAfterCleanup($path, $cutoff)) {
                    continue;
                }

                ++$unreferencedDocumentPaths;
                if (!$this->storage->fileExists($path)) {
                    ++$missingDocuments;
                }
            }
        } while (self::BATCH_SIZE === count($paths));

        return [$candidateDocumentPaths, $unreferencedDocumentPaths, $missingDocuments];
    }

    /**
     * @return array{int, int, int, int, int}
     */
    private function deletePrintJobsAndDocuments(\DateTimeImmutable $cutoff): array
    {
        $printJobsDeleted = 0;
        $candidateDocumentPaths = 0;
        $unreferencedDocumentPaths = 0;
        $documentsDeleted = 0;
        $missingDocuments = 0;

        while ([] !== $paths = $this->printJobRepository->findEligibleDocumentPaths($cutoff, self::BATCH_SIZE)) {
            foreach ($paths as $path) {
                ++$candidateDocumentPaths;
                $printJobsDeleted += $this->deleteEligiblePrintJobs($cutoff, $path);

                if ($this->printJobRepository->hasDocumentReference($path)) {
                    continue;
                }

                ++$unreferencedDocumentPaths;
                if ($this->deleteDocument($path)) {
                    ++$documentsDeleted;
                } else {
                    ++$missingDocuments;
                }
            }
        }

        $printJobsDeleted += $this->deleteEligiblePrintJobs($cutoff, null);

        return [$printJobsDeleted, $candidateDocumentPaths, $unreferencedDocumentPaths, $documentsDeleted, $missingDocuments];
    }

    private function deleteEligiblePrintJobs(\DateTimeImmutable $cutoff, ?string $documentPath): int
    {
        $deleted = 0;

        while (true) {
            $printJobs = $this->printJobRepository->findEligibleForCleanup($cutoff, self::BATCH_SIZE, $documentPath);
            if ([] === $printJobs) {
                break;
            }

            $deleted += count($printJobs);
            $this->entityManager->wrapInTransaction(function () use ($printJobs): void {
                foreach ($printJobs as $printJob) {
                    $this->entityManager->remove($printJob);
                }
            });
            $this->entityManager->clear();
        }

        return $deleted;
    }

    private function deleteDocument(string $path): bool
    {
        if (!$this->storage->fileExists($path)) {
            return false;
        }

        try {
            $this->storage->delete($path);

            return true;
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('Unable to delete document "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }
    }

    private function deleteOrders(\DateTimeImmutable $cutoff): int
    {
        $deleted = 0;

        while (true) {
            $orders = $this->orderRepository->findEligibleForCleanup($cutoff, self::BATCH_SIZE);
            if ([] === $orders) {
                break;
            }

            $deleted += count($orders);
            $this->entityManager->wrapInTransaction(function () use ($orders): void {
                foreach ($orders as $order) {
                    foreach ($order->getItems()->toArray() as $item) {
                        $order->removeItem($item);
                    }

                    $this->entityManager->remove($order);
                }
            });
            $this->entityManager->clear();
        }

        return $deleted;
    }
}
