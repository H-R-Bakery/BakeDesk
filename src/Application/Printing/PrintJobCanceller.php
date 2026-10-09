<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\PrintJob;
use App\Model\PrintJobStatus;
use Doctrine\ORM\EntityManagerInterface;

final class PrintJobCanceller
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?OrderRealtimePublisher $realtimePublisher = null,
    ) {
    }

    public function cancel(PrintJob $printJob): void
    {
        if (!in_array($printJob->getStatus(), [
            PrintJobStatus::QUEUED,
            PrintJobStatus::PROCESSING,
            PrintJobStatus::RENDERED,
        ], true)) {
            throw new \LogicException(sprintf('Print job %d cannot be cancelled from the %s status.', $printJob->getId(), $printJob->getStatus()->value));
        }

        $this->entityManager->wrapInTransaction(static function () use ($printJob): void {
            $printJob
                ->setStatus(PrintJobStatus::CANCELLED)
                ->setErrorMessage('Cancelled by an administrator.');
        });

        $this->realtimePublisher?->publishPrintJobUpdated($printJob);
    }
}
