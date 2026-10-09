<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;
use App\Model\PrintDocumentType;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsAlias(PrinterClientInterface::class)]
final class IppPrinterClient implements PrinterClientInterface
{
    public function __construct(
        private readonly IppTransportInterface $transport,
        #[Target('documents.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public function submitPdf(Printer $printer, string $documentPath, string $jobName, PrintDocumentType $documentType = PrintDocumentType::LABEL): PrintSubmission
    {
        $this->assertPrinterCanReceive($printer, $documentType);
        if ('' === $documentPath) {
            throw PrinterSubmissionException::rejected('The print job has no rendered document path.');
        }

        try {
            $pdf = $this->storage->read($documentPath);
        } catch (\Throwable $exception) {
            throw PrinterSubmissionException::rejected(sprintf('The rendered document "%s" could not be read.', $documentPath));
        }

        if (!str_starts_with($pdf, '%PDF-')) {
            throw PrinterSubmissionException::rejected('The rendered document is not a PDF document.');
        }

        $status = $this->getPrinterStatus($printer);
        if (!$status->supportsDocumentFormat('application/pdf')) {
            throw PrinterSubmissionException::rejected(sprintf('Printer "%s" does not advertise application/pdf support.', $printer->getName()));
        }
        if (false === $status->acceptsJobs) {
            throw PrinterSubmissionException::rejected(sprintf('Printer "%s" is not accepting jobs.', $printer->getName()));
        }

        try {
            return $this->transport->submitPdf($printer, $pdf, $jobName);
        } catch (PrinterSubmissionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw PrinterSubmissionException::outcomeUnknown($exception);
        }
    }

    public function getPrinterStatus(Printer $printer): PrinterStatus
    {
        $this->assertPrinterAddress($printer);

        try {
            return $this->transport->getPrinterStatus($printer);
        } catch (\Throwable $exception) {
            throw PrinterStatusException::unavailable($exception);
        }
    }

    public function getJobStatus(Printer $printer, string $externalJobId): PrintJobStatusSnapshot
    {
        $this->assertPrinterAddress($printer);
        if ('' === $externalJobId) {
            throw new \InvalidArgumentException('An external IPP job identifier is required.');
        }

        return $this->transport->getJobStatus($printer, $externalJobId);
    }

    private function assertPrinterCanReceive(Printer $printer, PrintDocumentType $documentType): void
    {
        $this->assertPrinterAddress($printer);
        if (!$printer->isActive()) {
            throw PrinterSubmissionException::rejected('The configured printer is inactive.');
        }

        if (PrintDocumentType::LABEL === $documentType && !$printer->isForLabels()) {
            throw PrinterSubmissionException::rejected('The configured printer is not enabled for labels.');
        }
        if (PrintDocumentType::REPORT === $documentType && !$printer->isForReports()) {
            throw PrinterSubmissionException::rejected('The configured printer is not enabled for reports.');
        }
    }

    private function assertPrinterAddress(Printer $printer): void
    {
        if ('' === trim($printer->getAddress())) {
            throw PrinterSubmissionException::rejected('The configured printer has no IPP address.');
        }
    }
}
