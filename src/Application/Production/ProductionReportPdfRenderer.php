<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Document\ConfigurableDocumentRendererInterface;
use App\Application\Document\RenderedDocument;
use App\Application\Order\BakeryClock;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Twig\Environment;

final readonly class ProductionReportPdfRenderer
{
    public function __construct(
        private Environment $twig,
        private ConfigurableDocumentRendererInterface $documentRenderer,
        #[Target('documents.storage')]
        private FilesystemOperator $storage,
        private LoggerInterface $logger,
        private BakeryClock $bakeryClock,
    ) {
    }

    public function render(ProductionReport $report, ?string $pathSuffix = null): RenderedDocument
    {
        $date = $report->pickupDate->format('Y-m-d');

        try {
            $filenameStem = null === $pathSuffix
                ? sprintf('production-%s', $date)
                : sprintf('production-%s-%s', $date, $pathSuffix);
            $html = $this->twig->render('report/production.pdf.html.twig', [
                'report' => $report,
                'bakery_timezone' => $this->bakeryClock->getTimezoneName(),
            ]);
            $contents = $this->documentRenderer->renderHtmlToPdfWithOptions(
                $html,
                '8.5in',
                '11in',
                $filenameStem,
            );
            if (!str_starts_with($contents, '%PDF-')) {
                throw new \UnexpectedValueException('Gotenberg returned an invalid PDF document.');
            }

            $filename = sprintf('%s.pdf', $filenameStem);
            $path = sprintf(
                'reports/production/%s/%s/%s',
                $report->pickupDate->format('Y'),
                $report->pickupDate->format('m'),
                $filename,
            );
            $this->storage->write($path, $contents);

            return new RenderedDocument($path, 'application/pdf', $filename, $contents);
        } catch (\Throwable $exception) {
            $this->logger->error('Production report rendering failed.', [
                'pickup_date' => $date,
                'exception' => $exception,
            ]);

            throw ProductionReportRenderingException::forDate($date, $exception);
        }
    }
}
