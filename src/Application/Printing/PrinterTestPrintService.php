<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;
use App\Model\PrintDocumentType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PrinterTestPrintService
{
    public function __construct(
        private PrinterTestDocumentGenerator $documentGenerator,
        private PrinterClientInterface $printerClient,
        #[Autowire('%bakedesk_brand_name%')]
        private string $bakedeskBrandName,
    ) {
    }

    public function submit(Printer $printer, PrintDocumentType $documentType): PrintSubmission
    {
        if (!$printer->isActive()) {
            throw PrinterSubmissionException::rejected(sprintf('Printer "%s" is inactive.', $printer->getName()));
        }

        if (PrintDocumentType::LABEL === $documentType && !$printer->isForLabels()) {
            throw PrinterSubmissionException::rejected(sprintf('Printer "%s" is not enabled for labels.', $printer->getName()));
        }

        if (PrintDocumentType::REPORT === $documentType && !$printer->isForReports()) {
            throw PrinterSubmissionException::rejected(sprintf('Printer "%s" is not enabled for reports.', $printer->getName()));
        }

        $document = $this->documentGenerator->render($printer, $documentType);
        $testName = PrintDocumentType::LABEL === $documentType ? 'Label' : 'Report';

        return $this->printerClient->submitPdf(
            $printer,
            $document->path,
            sprintf('%s %s Printer Test', $this->bakedeskBrandName, $testName),
            $documentType,
        );
    }
}
