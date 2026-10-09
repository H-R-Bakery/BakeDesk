<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Message\ProcessPrintJob;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReportPrintJobCreator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrintJobRepository $printJobRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function createAndDispatch(Printer $printer, \DateTimeImmutable $reportDate): PrintJob
    {
        if (!$printer->isActive() || !$printer->isForReports()) {
            throw ReportPrinterUnavailableException::forPrinter();
        }

        /** @var PrintJob $printJob */
        $printJob = $this->entityManager->wrapInTransaction(function () use ($printer, $reportDate): PrintJob {
            $printJob = (new PrintJob())
                ->setPrinter($printer)
                ->setDocumentType(PrintDocumentType::REPORT)
                ->setStatus(PrintJobStatus::QUEUED)
                ->setOrder(null)
                ->setOrderItem(null)
                ->setPackageNumber(null)
                ->setPackageCount(null)
                ->setPackageQuantity(null)
                ->setReportDate($reportDate)
                ->setDocumentPath(null)
                ->setAttemptCount(0);

            $this->printJobRepository->save($printJob);

            return $printJob;
        });

        $printJobId = $printJob->getId();
        if (null === $printJobId) {
            throw new \LogicException('The report print job was not assigned an identifier.');
        }

        $this->messageBus->dispatch(new ProcessPrintJob($printJobId));

        return $printJob;
    }
}
