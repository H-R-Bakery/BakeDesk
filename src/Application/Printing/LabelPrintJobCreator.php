<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Application\Packaging\OrderPackageCalculator;
use App\Entity\Order;
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
    ) {
    }

    /**
     * @return list<PrintJob>
     */
    public function createAndDispatch(Order $order): array
    {
        $printer = $this->labelPrinterResolver->resolve();
        $allocations = $this->orderPackageCalculator->calculate($order);

        /** @var list<PrintJob> $printJobs */
        $printJobs = $this->entityManager->wrapInTransaction(function () use ($order, $printer, $allocations): array {
            $printJobs = [];
            foreach ($allocations as $allocation) {
                $printJob = (new PrintJob())
                    ->setPrinter($printer)
                    ->setDocumentType(PrintDocumentType::LABEL)
                    ->setStatus(PrintJobStatus::QUEUED)
                    ->setOrder($order)
                    ->setOrderItem($allocation->orderItem)
                    ->setPackageNumber($allocation->packageNumber)
                    ->setPackageCount($allocation->packageCount)
                    ->setPackageQuantity($allocation->quantity)
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

            $this->messageBus->dispatch(new ProcessPrintJob($printJobId));
        }

        return $printJobs;
    }
}
