<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Document\OrderLabelRenderer;
use App\Application\Order\BakeryClock;
use App\Application\Packaging\PackageAllocation;
use App\Application\Printing\IppJobStateMapper;
use App\Application\Printing\PrinterClientInterface;
use App\Application\Printing\PrinterSubmissionException;
use App\Application\Printing\ProductionReportPrintDocumentGenerator;
use App\Application\Realtime\OrderRealtimePublisher;
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
        private readonly ?ProductionReportPrintDocumentGenerator $reportDocumentGenerator = null,
        private readonly ?OrderRealtimePublisher $realtimePublisher = null,
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

        if (!in_array($printJob->getDocumentType(), [PrintDocumentType::LABEL, PrintDocumentType::REPORT], true)) {
            throw new \LogicException('The print job has an unsupported document type.');
        }

        if (PrintJobStatus::QUEUED === $printJob->getStatus()) {
            $this->render($printJob);
        }

        if (PrintJobStatus::RENDERED !== $printJob->getStatus()) {
            return;
        }

        $printer = $printJob->getPrinter();
        $documentType = $printJob->getDocumentType();
        if (null === $printer || null === $documentType) {
            throw new \LogicException('A print job requires a document type and printer.');
        }

        try {
            $submission = PrintDocumentType::REPORT === $documentType
                ? $this->printerClient->submitPdf($printer, (string) $printJob->getDocumentPath(), $this->jobName($printJob), $documentType)
                : $this->printerClient->submitPdf($printer, (string) $printJob->getDocumentPath(), $this->jobName($printJob));
        } catch (PrinterSubmissionException $exception) {
            $this->markFailed($printJob, $exception->getMessage());
            $this->logger->error('Print job submission failed.', [
                'print_job_id' => $printJob->getId(),
                'document_type' => $documentType->value,
                'exception' => $exception,
            ]);

            return;
        } catch (\Throwable $exception) {
            $this->markFailed($printJob, $exception->getMessage());
            $this->logger->error('Print job submission failed before acceptance could be confirmed.', [
                'print_job_id' => $printJob->getId(),
                'document_type' => $documentType->value,
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
        $this->publishStatus($printJob);

        if (null === $mappedInitialStatus || PrintJobStatus::SUBMITTED === $mappedInitialStatus) {
            $printJobId = $printJob->getId();
            if (null !== $printJobId) {
                $this->messageBus->dispatch(new RefreshPrintJobStatus($printJobId), [new DelayStamp(2000)]);
            }
        }
    }

    private function render(PrintJob $printJob): void
    {
        $printer = $printJob->getPrinter();
        $documentType = $printJob->getDocumentType();
        if (null === $printer || null === $documentType) {
            throw new \LogicException('A print job requires a document type and printer.');
        }
        $supportsDocument = PrintDocumentType::LABEL === $documentType
            ? $printer->isForLabels()
            : $printer->isForReports();
        if (!$printer->isActive() || !$supportsDocument) {
            $this->markFailed($printJob, PrintDocumentType::LABEL === $documentType
                ? 'A label print job requires an active label printer.'
                : 'A report print job requires an active report printer.');

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
        $this->publishStatus($printJob);

        try {
            $document = PrintDocumentType::REPORT === $documentType
                ? $this->renderReport($printJob)
                : $this->orderLabelRenderer->render($this->labelSubject($printJob));
        } catch (\Throwable $exception) {
            $subject = PrintDocumentType::REPORT === $documentType
                ? sprintf('the production report for %s', $printJob->getReportDate()?->format('Y-m-d') ?? 'the selected date')
                : sprintf('the label for order %s', $printJob->getOrder()?->getOrderNumber() ?? 'unknown');
            $this->markFailed($printJob, sprintf('Unable to render %s.', $subject));
            $this->logger->error('Print job document rendering failed.', [
                'print_job_id' => $printJob->getId(),
                'document_type' => $documentType->value,
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
        $this->publishStatus($printJob);
    }

    private function labelSubject(PrintJob $printJob): \App\Entity\Order|PackageAllocation
    {
        $order = $printJob->getOrder();
        if (null === $order) {
            throw new \LogicException('A label print job requires an order.');
        }

        $orderItem = $printJob->getOrderItem();
        if (null === $orderItem && null === $printJob->getPackageNumber() && null === $printJob->getPackageCount() && null === $printJob->getPackageQuantity()) {
            return $order;
        }

        if (null === $orderItem || null === $printJob->getPackageNumber() || null === $printJob->getPackageCount() || null === $printJob->getPackageQuantity() || null === $orderItem->getUnit()) {
            throw new \LogicException('A package label print job requires complete package metadata.');
        }

        return new PackageAllocation(
            orderItem: $orderItem,
            packageNumber: $printJob->getPackageNumber(),
            packageCount: $printJob->getPackageCount(),
            quantity: $printJob->getPackageQuantity(),
            unit: $orderItem->getUnit(),
        );
    }

    private function jobName(PrintJob $printJob): string
    {
        if (PrintDocumentType::REPORT === $printJob->getDocumentType()) {
            $reportDate = $printJob->getReportDate();
            if (null === $reportDate) {
                throw new \LogicException('A report print job requires a report date.');
            }

            return sprintf('BakeDesk Production Report %s', $reportDate->format('Y-m-d'));
        }

        $order = $printJob->getOrder();
        if (null === $order) {
            throw new \LogicException('A label print job requires an order.');
        }

        if (null !== $printJob->getPackageNumber() && null !== $printJob->getPackageCount()) {
            return sprintf('BakeDesk Order #%s Box %d of %d', $order->getOrderNumber(), $printJob->getPackageNumber(), $printJob->getPackageCount());
        }

        return sprintf('BakeDesk Order #%s', $order->getOrderNumber());
    }

    private function renderReport(PrintJob $printJob): \App\Application\Document\RenderedDocument
    {
        if (null === $this->reportDocumentGenerator) {
            throw new \LogicException('The production report print document generator is not configured.');
        }

        return $this->reportDocumentGenerator->render($printJob);
    }

    private function markFailed(PrintJob $printJob, string $message): void
    {
        $this->entityManager->wrapInTransaction(static function () use ($printJob, $message): void {
            $printJob
                ->setStatus(PrintJobStatus::FAILED)
                ->setErrorMessage($message);
        });
        $this->publishStatus($printJob);
    }

    private function publishStatus(PrintJob $printJob): void
    {
        $this->realtimePublisher?->publishPrintJobUpdated($printJob);
    }
}
