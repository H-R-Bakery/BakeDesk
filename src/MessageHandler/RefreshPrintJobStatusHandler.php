<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Order\BakeryClock;
use App\Application\Printing\IppJobStateMapper;
use App\Application\Printing\PrinterClientInterface;
use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\PrintJob;
use App\Message\RefreshPrintJobStatus;
use App\Model\PrintJobStatus;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final class RefreshPrintJobStatusHandler
{
    /** @var list<int> */
    private const REFRESH_DELAYS_MS = [2000, 5000, 10000, 20000, 30000];

    public function __construct(
        private readonly PrintJobRepository $printJobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PrinterClientInterface $printerClient,
        private readonly IppJobStateMapper $jobStateMapper,
        private readonly BakeryClock $bakeryClock,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ?OrderRealtimePublisher $realtimePublisher = null,
    ) {
    }

    public function __invoke(RefreshPrintJobStatus $message): void
    {
        $printJob = $this->printJobRepository->find($message->printJobId);
        if (!$printJob instanceof PrintJob || PrintJobStatus::SUBMITTED !== $printJob->getStatus()) {
            return;
        }

        $externalJobId = $printJob->getExternalJobId();
        $printer = $printJob->getPrinter();
        if (null === $externalJobId || null === $printer) {
            $this->logger->warning('Submitted print job has no external printer job identifier or printer.', [
                'print_job_id' => $printJob->getId(),
            ]);

            return;
        }

        try {
            $snapshot = $this->printerClient->getJobStatus($printer, $externalJobId);
        } catch (\Throwable $exception) {
            $this->handleIndeterminateStatus($printJob, $message->checkNumber, $exception);

            return;
        }

        $mappedStatus = $this->jobStateMapper->toPrintJobStatus($snapshot);
        if (PrintJobStatus::SUBMITTED === $mappedStatus) {
            $this->scheduleNextRefresh($printJob, $message->checkNumber);

            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($printJob, $mappedStatus, $snapshot): void {
            $printJob->setStatus($mappedStatus);
            if (PrintJobStatus::COMPLETED === $mappedStatus) {
                $printJob->setCompletedAt($this->bakeryClock->now())->setErrorMessage(null);
            } elseif (PrintJobStatus::CANCELLED === $mappedStatus) {
                $printJob->setErrorMessage($snapshot->diagnosticMessage());
            } elseif (PrintJobStatus::FAILED === $mappedStatus) {
                $printJob->setErrorMessage($snapshot->diagnosticMessage() ?? 'The printer aborted the job.');
            }
        });
        $this->realtimePublisher?->publishPrintJobUpdated($printJob);
    }

    private function scheduleNextRefresh(PrintJob $printJob, int $checkNumber): void
    {
        $delay = self::REFRESH_DELAYS_MS[$checkNumber] ?? null;
        if (null === $delay) {
            $this->logger->warning('Print job completion remains unconfirmed after the polling window.', [
                'print_job_id' => $printJob->getId(),
                'external_job_id' => $printJob->getExternalJobId(),
            ]);

            return;
        }

        $printJobId = $printJob->getId();
        if (null !== $printJobId) {
            $this->messageBus->dispatch(
                new RefreshPrintJobStatus($printJobId, $checkNumber + 1),
                [new DelayStamp($delay)],
            );
        }
    }

    private function handleIndeterminateStatus(PrintJob $printJob, int $checkNumber, \Throwable $exception): void
    {
        $this->logger->warning('Could not refresh the external print job status.', [
            'print_job_id' => $printJob->getId(),
            'external_job_id' => $printJob->getExternalJobId(),
            'exception' => $exception,
        ]);
        $this->scheduleNextRefresh($printJob, $checkNumber);
    }
}
