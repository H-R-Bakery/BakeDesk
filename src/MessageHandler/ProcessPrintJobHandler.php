<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Document\OrderLabelRenderer;
use App\Application\Order\BakeryClock;
use App\Application\Printing\IppJobStateMapper;
use App\Application\Printing\PrinterClientInterface;
use App\Application\Printing\PrinterSubmissionException;
use App\Entity\PrintJob;
use App\Message\ProcessPrintJob;
use App\Message\RefreshPrintJobStatus;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final class ProcessPrintJobHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrintJobRepository $printJobRepository,
        private readonly OrderLabelRenderer $orderLabelRenderer,
        private readonly BakeryClock $bakeryClock,
        private readonly PrinterClientInterface $printerClient,
        private readonly IppJobStateMapper $jobStateMapper,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessPrintJob $message): void
    {
        $printJob = $this->printJobRepository->find($message->printJobId);
        if (!$printJob instanceof PrintJob) {
            throw new \UnexpectedValueException(sprintf('Print job %d was not found.', $message->printJobId));
        }

        if (null !== $printJob->getExternalJobId()
            || PrintJobStatus::FAILED === $printJob->getStatus()
            || PrintJobStatus::SUBMITTED === $printJob->getStatus()
            || PrintJobStatus::COMPLETED === $printJob->getStatus()
            || PrintJobStatus::CANCELLED === $printJob->getStatus()
        ) {
            return;
        }

        if (PrintDocumentType::LABEL !== $printJob->getDocumentType()) {
            throw new \LogicException('Only label print jobs can be processed by this handler.');
        }

        if (PrintJobStatus::QUEUED === $printJob->getStatus()) {
            $this->render($printJob);
        }

        if (PrintJobStatus::RENDERED !== $printJob->getStatus()) {
            return;
        }

        $order = $printJob->getOrder();
        $printer = $printJob->getPrinter();
        if (null === $order || null === $printer) {
            throw new \LogicException('A label print job requires an order and printer.');
        }

        try {
            $submission = $this->printerClient->submitPdf(
                $printer,
                (string) $printJob->getDocumentPath(),
                sprintf('BakeDesk Order #%s', $order->getOrderNumber()),
            );
        } catch (PrinterSubmissionException $exception) {
            $this->markFailed($printJob, $exception->getMessage());
            $this->logger->error('Print job label submission failed.', [
                'print_job_id' => $printJob->getId(),
                'order_id' => $order->getId(),
                'exception' => $exception,
            ]);

            return;
        } catch (\Throwable $exception) {
            $this->markFailed($printJob, $exception->getMessage());
            $this->logger->error('Print job label submission failed before acceptance could be confirmed.', [
                'print_job_id' => $printJob->getId(),
                'order_id' => $order->getId(),
                'exception' => $exception,
            ]);

            return;
        }

        $now = $this->bakeryClock->now();
        $mappedInitialStatus = null !== $submission->initialStatus
            ? $this->jobStateMapper->toPrintJobStatus($submission->initialStatus)
            : null;
        $this->entityManager->wrapInTransaction(function () use ($printJob, $submission, $mappedInitialStatus, $now): void {
            $printJob
                ->setStatus(PrintJobStatus::SUBMITTED)
                ->setSubmittedAt($now)
                ->setExternalJobId($submission->externalJobId)
                ->setErrorMessage(null);

            if (PrintJobStatus::COMPLETED === $mappedInitialStatus) {
                $printJob
                    ->setStatus(PrintJobStatus::COMPLETED)
                    ->setCompletedAt($now);
            } elseif (PrintJobStatus::CANCELLED === $mappedInitialStatus) {
                $printJob
                    ->setStatus(PrintJobStatus::CANCELLED)
                    ->setErrorMessage($submission->initialStatus?->diagnosticMessage());
            } elseif (PrintJobStatus::FAILED === $mappedInitialStatus) {
                $printJob
                    ->setStatus(PrintJobStatus::FAILED)
                    ->setErrorMessage($submission->initialStatus?->diagnosticMessage() ?? 'The printer aborted the job.');
            }
        });

        if (null === $mappedInitialStatus || PrintJobStatus::SUBMITTED === $mappedInitialStatus) {
            $printJobId = $printJob->getId();
            if (null !== $printJobId) {
                $this->messageBus->dispatch(new RefreshPrintJobStatus($printJobId), [new DelayStamp(2000)]);
            }
        }
    }

    private function render(PrintJob $printJob): void
    {
        $order = $printJob->getOrder();
        $printer = $printJob->getPrinter();
        if (null === $order || null === $printer) {
            throw new \LogicException('A label print job requires an order and printer.');
        }
        if (!$printer->isActive() || !$printer->isForLabels()) {
            $this->markFailed($printJob, 'A label print job requires an active label printer.');

            return;
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
            $this->markFailed($printJob, sprintf('Unable to render the label for order %s.', $order->getOrderNumber()));
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

    private function markFailed(PrintJob $printJob, string $message): void
    {
        $this->entityManager->wrapInTransaction(static function () use ($printJob, $message): void {
            $printJob
                ->setStatus(PrintJobStatus::FAILED)
                ->setErrorMessage($message);
        });
    }
}
