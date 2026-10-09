<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Application\Document\RenderedDocument;
use App\Application\Production\ProductionReportBuilder;
use App\Application\Production\ProductionReportPdfRenderer;
use App\Entity\PrintJob;

final readonly class ProductionReportPrintDocumentGenerator
{
    public function __construct(
        private ProductionReportBuilder $reportBuilder,
        private ProductionReportPdfRenderer $pdfRenderer,
    ) {
    }

    public function render(PrintJob $printJob): RenderedDocument
    {
        $reportDate = $printJob->getReportDate();
        $printJobId = $printJob->getId();
        if (null === $reportDate || null === $printJobId) {
            throw new \LogicException('A report print job requires a report date and identifier.');
        }

        return $this->pdfRenderer->render(
            $this->reportBuilder->build($reportDate),
            sprintf('print-job-%d', $printJobId),
        );
    }
}
