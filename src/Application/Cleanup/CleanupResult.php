<?php

declare(strict_types=1);

namespace App\Application\Cleanup;

final readonly class CleanupResult
{
    public function __construct(
        public bool $dryRun,
        public \DateTimeImmutable $printJobCutoff,
        public \DateTimeImmutable $orderCutoff,
        public int $eligiblePrintJobs,
        public int $printJobsDeleted,
        public int $candidateDocumentPaths,
        public int $unreferencedDocumentPaths,
        public int $documentsDeleted,
        public int $missingDocuments,
        public int $eligibleOrders,
        public int $ordersDeleted,
    ) {
    }
}
