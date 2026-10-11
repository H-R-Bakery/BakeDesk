<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Application\Packaging\OrderPackageCalculator;
use App\Application\Packaging\PackageAllocation;
use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\Order;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Message\ProcessPrintJob;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class LabelPrintJobCreator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrintJobRepository $printJobRepository,
        private readonly LabelPrinterResolver $labelPrinterResolver,
        private readonly OrderPackageCalculator $orderPackageCalculator,
        private readonly MessageBusInterface $messageBus,
        private readonly ?OrderRealtimePublisher $realtimePublisher = null,
    ) {
    }

    /**
     * @return list<PrintJob>
     */
    public function createAndDispatch(Order $order): array
    {
        $printer = $this->labelPrinterResolver->resolve();
        $allocations = $this->orderPackageCalculator->calculate($order);

        return $this->createAndDispatchForAllocations($order, $printer, $allocations);
    }

    public function createAllocationAndDispatch(Order $order, PackageAllocation $allocation): PrintJob
    {
        if ($allocation->getOrder() !== $order) {
            throw new \InvalidArgumentException('The package allocation does not belong to the order.');
        }

        $printer = $this->labelPrinterResolver->resolve();
        $printJobs = $this->createAndDispatchForAllocations($order, $printer, [$allocation]);

        return $printJobs[0] ?? throw new \LogicException('The label print job was not created.');
    }

    /**
     * @param list<PackageAllocation> $allocations
     *
     * @return list<PrintJob>
     */
    private function createAndDispatchForAllocations(Order $order, Printer $printer, array $allocations): array
    {
        $labelSetId = bin2hex(random_bytes(16));
        /** @var list<PrintJob> $printJobs */
        $printJobs = $this->entityManager->wrapInTransaction(function () use ($order, $printer, $allocations, $labelSetId): array {
            $printJobs = [];
            foreach ($allocations as $allocation) {
                if ($allocation->getOrder() !== $order) {
                    throw new \InvalidArgumentException('Every package allocation must belong to the order.');
                }

                $printJob = (new PrintJob())
                    ->setPrinter($printer)
                    ->setDocumentType(PrintDocumentType::LABEL)
                    ->setStatus(PrintJobStatus::QUEUED)
                    ->setOrder($order)
                    ->setPackageNumber($allocation->packageNumber)
                    ->setPackageCount($allocation->packageCount)
                    ->setPackageSnapshot($allocation->toSnapshot())
                    ->setLabelSetId($labelSetId)
                    ->setReportDate(null)
                    ->setAttemptCount(0)
                    ->setDocumentPath(null);

                $this->printJobRepository->save($printJob);
                $printJobs[] = $printJob;
            }

            return $printJobs;
        });

        foreach ($printJobs as $printJob) {
            $printJobId = $printJob->getId();
            if (null === $printJobId) {
                throw new \LogicException('The label print job was not assigned an identifier.');
            }

            $this->realtimePublisher?->publishPrintJobUpdated($printJob);
            $this->messageBus->dispatch(new ProcessPrintJob($printJobId));
        }

        return $printJobs;
    }
}
