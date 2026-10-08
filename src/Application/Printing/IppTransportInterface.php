<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;

interface IppTransportInterface
{
    public function getPrinterStatus(Printer $printer): PrinterStatus;

    public function submitPdf(Printer $printer, string $pdf, string $jobName): PrintSubmission;

    public function getJobStatus(Printer $printer, string $externalJobId): PrintJobStatusSnapshot;
}
