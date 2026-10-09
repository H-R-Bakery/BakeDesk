<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\PrintJob;
use App\Model\PrintJobStatus;
use Doctrine\ORM\EntityManagerInterface;

final class PrintJobDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function delete(PrintJob $printJob): void
    {
        if (!in_array($printJob->getStatus(), [
            PrintJobStatus::COMPLETED,
            PrintJobStatus::FAILED,
            PrintJobStatus::CANCELLED,
        ], true)) {
            throw new \LogicException(sprintf('Print job %d cannot be deleted from the %s status.', $printJob->getId(), $printJob->getStatus()->value));
        }

        // Generated documents can be shared by retries or deterministic report paths.
        // Storage cleanup therefore remains separate from deleting this history row.
        $this->entityManager->remove($printJob);
        $this->entityManager->flush();
    }
}
