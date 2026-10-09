<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Application\Document\ConfigurableDocumentRendererInterface;
use App\Application\Document\RenderedDocument;
use App\Application\Order\BakeryClock;
use App\Entity\Printer;
use App\Model\PrintDocumentType;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Twig\Environment;

final class PrinterTestDocumentGenerator
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ConfigurableDocumentRendererInterface $documentRenderer,
        #[Target('documents.storage')]
        private readonly FilesystemOperator $storage,
        private readonly BakeryClock $bakeryClock,
        private readonly LoggerInterface $logger,
        #[Autowire('%bakery_print_logo_path%')]
        private readonly string $printLogoPath = 'assets/Images/HRBakeryLogo_bw.svg',
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory = __DIR__.'/../../..',
    ) {
    }

    public function render(Printer $printer, PrintDocumentType $documentType): RenderedDocument
    {
        $printerId = $printer->getId();
        if (null === $printerId) {
            throw new \LogicException('A printer test document requires a persisted printer.');
        }

        $generatedAt = $this->bakeryClock->now();
        $timestamp = $generatedAt->format('Ymd-His-u');
        [$template, $paperWidth, $paperHeight, $filenameStem] = match ($documentType) {
            PrintDocumentType::LABEL => ['admin/printer_test/label.html.twig', '4in', '6in', 'label-test-'.$timestamp],
            PrintDocumentType::REPORT => ['admin/printer_test/report.html.twig', '8.5in', '11in', 'report-test-'.$timestamp],
        };

        try {
            $html = $this->twig->render($template, [
                'printer' => $printer,
                'generated_at' => $generatedAt,
                'bakery_timezone' => $this->bakeryClock->getTimezoneName(),
                'logo_svg' => $this->loadLogoSvg(),
            ]);
            $contents = $this->documentRenderer->renderHtmlToPdfWithOptions(
                $html,
                $paperWidth,
                $paperHeight,
                $filenameStem,
            );
            if (!str_starts_with($contents, '%PDF-')) {
                throw new \UnexpectedValueException('The document renderer returned an invalid PDF document.');
            }

            $filename = $filenameStem.'.pdf';
            $path = sprintf('diagnostics/printers/%d/%s', $printerId, $filename);
            $this->storage->write($path, $contents);

            return new RenderedDocument($path, 'application/pdf', $filename, $contents);
        } catch (PrinterTestDocumentRenderingException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->error('Printer test document rendering failed.', [
                'printer_id' => $printerId,
                'document_type' => $documentType->value,
                'exception' => $exception,
            ]);

            throw PrinterTestDocumentRenderingException::forPrinter($printer, $documentType, $exception);
        }
    }

    private function loadLogoSvg(): string
    {
        $path = str_starts_with($this->printLogoPath, '/')
            ? $this->printLogoPath
            : $this->projectDirectory.'/'.$this->printLogoPath;
        $logo = file_get_contents($path);
        if (false === $logo || !str_contains($logo, '<svg')) {
            throw new \UnexpectedValueException('The configured print logo could not be loaded.');
        }

        return $logo;
    }
}
