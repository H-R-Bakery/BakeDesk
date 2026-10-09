<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\PrintJob;
use App\Message\ProcessPrintJob;
use App\Model\OrderStatus;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class LabelPrintJobRetryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrintJobRepository $printJobRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly ?OrderRealtimePublisher $realtimePublisher = null,
    ) {
    }

    public function retry(PrintJob $failedJob): PrintJob
    {
        if (PrintJobStatus::FAILED !== $failedJob->getStatus() || PrintDocumentType::LABEL !== $failedJob->getDocumentType()) {
            throw PrintJobRetryException::onlyFailedLabels();
        }

        $printer = $failedJob->getPrinter();
        if (null === $printer || !$printer->isActive() || !$printer->isForLabels()) {
            throw PrintJobRetryException::unavailablePrinter();
        }

        $order = $failedJob->getOrder();
        if (null === $order) {
            throw PrintJobRetryException::missingOrder();
        }
        if (OrderStatus::CANCELLED === $order->getStatus()) {
            throw PrintJobRetryException::cancelledOrder();
        }

        $this->validatePackageContext($failedJob);
        $documentPath = $failedJob->getDocumentPath();

        /** @var PrintJob $retryJob */
        $retryJob = $this->entityManager->wrapInTransaction(function () use ($failedJob, $printer, $order, $documentPath): PrintJob {
            $retryJob = (new PrintJob())
                ->setPrinter($printer)
                ->setDocumentType(PrintDocumentType::LABEL)
                ->setStatus(null !== $documentPath ? PrintJobStatus::RENDERED : PrintJobStatus::QUEUED)
                ->setOrder($order)
                ->setOrderItem($failedJob->getOrderItem())
                ->setPackageNumber($failedJob->getPackageNumber())
                ->setPackageCount($failedJob->getPackageCount())
                ->setPackageQuantity($failedJob->getPackageQuantity())
                ->setReportDate(null)
                ->setExternalJobId(null)
                ->setDocumentPath($documentPath)
                ->setAttemptCount(0)
                ->setStartedAt(null)
                ->setSubmittedAt(null)
                ->setCompletedAt(null)
                ->setErrorMessage(null);

            $this->printJobRepository->save($retryJob);

            return $retryJob;
        });

        $retryJobId = $retryJob->getId();
        if (null === $retryJobId) {
            throw new \LogicException('The retry print job was not assigned an identifier.');
        }

        $this->realtimePublisher?->publishPrintJobUpdated($retryJob);
        $this->messageBus->dispatch(new ProcessPrintJob($retryJobId));

        return $retryJob;
    }

    private function validatePackageContext(PrintJob $failedJob): void
    {
        $hasOrderItem = null !== $failedJob->getOrderItem();
        $hasPackageNumber = null !== $failedJob->getPackageNumber();
        $hasPackageCount = null !== $failedJob->getPackageCount();
        $hasPackageQuantity = null !== $failedJob->getPackageQuantity();

        if ($hasOrderItem && $hasPackageNumber && $hasPackageCount && $hasPackageQuantity) {
            if ($failedJob->getOrderItem()->getOrder() !== $failedJob->getOrder()) {
                throw PrintJobRetryException::incompletePackageContext();
            }

            return;
        }

        if (!$hasOrderItem && !$hasPackageNumber && !$hasPackageCount && !$hasPackageQuantity) {
            return;
        }

        throw PrintJobRetryException::incompletePackageContext();
    }
}
