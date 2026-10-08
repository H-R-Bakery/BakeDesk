<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;

interface PrinterClientInterface
{
    public function submitPdf(Printer $printer, string $documentPath, string $jobName): PrintSubmission;

    public function getPrinterStatus(Printer $printer): PrinterStatus;

    public function getJobStatus(Printer $printer, string $externalJobId): PrintJobStatusSnapshot;
}
