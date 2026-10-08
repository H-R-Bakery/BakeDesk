<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Document\OrderLabelRenderer;
use App\Application\Order\BakeryClock;
use App\Entity\PrintJob;
use App\Message\ProcessPrintJob;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessPrintJobHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrintJobRepository $printJobRepository,
        private readonly OrderLabelRenderer $orderLabelRenderer,
        private readonly BakeryClock $bakeryClock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessPrintJob $message): void
    {
        $printJob = $this->printJobRepository->find($message->printJobId);
        if (!$printJob instanceof PrintJob) {
            throw new \UnexpectedValueException(sprintf('Print job %d was not found.', $message->printJobId));
        }

        if (PrintJobStatus::CANCELLED === $printJob->getStatus()
            || PrintJobStatus::SUBMITTED === $printJob->getStatus()
            || PrintJobStatus::COMPLETED === $printJob->getStatus()
            || (PrintJobStatus::RENDERED === $printJob->getStatus() && null !== $printJob->getDocumentPath())
        ) {
            return;
        }

        if (PrintDocumentType::LABEL !== $printJob->getDocumentType()) {
            throw new \LogicException('Only label print jobs can be processed by this handler.');
        }

        $order = $printJob->getOrder();
        $printer = $printJob->getPrinter();
        if (null === $order || null === $printer) {
            throw new \LogicException('A label print job requires an order and printer.');
        }
        if (!$printer->isActive() || !$printer->isForLabels()) {
            throw new \LogicException('A label print job requires an active label printer.');
        }

        $this->entityManager->wrapInTransaction(function () use ($printJob): void {
            $printJob
                ->setStatus(PrintJobStatus::PROCESSING)
                ->setStartedAt($this->bakeryClock->now())
                ->setAttemptCount($printJob->getAttemptCount() + 1)
                ->setErrorMessage(null)
                ->setDocumentPath(null);
        });

        try {
            $document = $this->orderLabelRenderer->render($order);
        } catch (\Throwable $exception) {
            $this->entityManager->wrapInTransaction(function () use ($printJob, $order): void {
                $printJob
                    ->setStatus(PrintJobStatus::FAILED)
                    ->setErrorMessage(sprintf('Unable to render the label for order %s.', $order->getOrderNumber()))
                    ->setDocumentPath(null);
            });

            $this->logger->error('Print job label rendering failed.', [
                'print_job_id' => $printJob->getId(),
                'order_id' => $order->getId(),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->entityManager->wrapInTransaction(function () use ($printJob, $document): void {
            $printJob
                ->setDocumentPath($document->path)
                ->setStatus(PrintJobStatus::RENDERED)
                ->setErrorMessage(null);
        });
    }
}
