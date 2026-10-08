<?php

declare(strict_types=1);

namespace App\Application\Printing;

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
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function createAndDispatch(Order $order): PrintJob
    {
        $printer = $this->labelPrinterResolver->resolve();

        $printJob = $this->entityManager->wrapInTransaction(function () use ($order, $printer): PrintJob {
            $printJob = (new PrintJob())
                ->setPrinter($printer)
                ->setDocumentType(PrintDocumentType::LABEL)
                ->setStatus(PrintJobStatus::QUEUED)
                ->setOrder($order)
                ->setReportDate(null)
                ->setAttemptCount(0)
                ->setDocumentPath(null);

            $this->printJobRepository->save($printJob);

            return $printJob;
        });

        $printJobId = $printJob->getId();
        if (null === $printJobId) {
            throw new \LogicException('The label print job was not assigned an identifier.');
        }

        $this->messageBus->dispatch(new ProcessPrintJob($printJobId));

        return $printJob;
    }
}
