<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Order\BakeryClock;
use App\Application\Printing\ReportPrinterUnavailableException;
use App\Application\Printing\ReportPrintJobCreator;
use App\Application\Production\ProductionReportBuilder;
use App\Application\Production\ProductionReportPdfRenderer;
use App\Application\Production\ProductionReportRenderingException;
use App\Entity\Printer;
use App\Repository\PrinterRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProductionReportController extends AbstractController
{
    #[Route('/reports/production', name: 'production_report', methods: ['GET'])]
    public function index(Request $request, BakeryClock $bakeryClock, ProductionReportBuilder $reportBuilder, PrinterRepository $printerRepository): Response
    {
        $report = $reportBuilder->build($this->parseReportDate($request, $bakeryClock));

        return $this->render('report/production.html.twig', [
            'report' => $report,
            'bakery_timezone' => $bakeryClock->getTimezoneName(),
            'report_printers' => $printerRepository->findAvailableForReports(),
        ]);
    }

    #[Route('/reports/production/print', name: 'production_report_print', methods: ['POST'])]
    public function printReport(
        Request $request,
        BakeryClock $bakeryClock,
        PrinterRepository $printerRepository,
        ReportPrintJobCreator $printJobCreator,
    ): Response {
        if (!$this->isCsrfTokenValid('print_report', (string) $request->request->get('_token'))) {
            return new Response('The production report print form is invalid.', Response::HTTP_FORBIDDEN);
        }

        $reportDate = $this->parseReportDate($request, $bakeryClock, $request->request);
        $printerId = filter_var($request->request->get('printer'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $printer = false === $printerId ? null : $printerRepository->find($printerId);

        if (!$printer instanceof Printer || !$printer->isActive() || !$printer->isForReports()) {
            $this->addFlash('error', 'The selected printer is not available for reports.');

            return $this->redirectToReportDate($reportDate);
        }

        try {
            $printJobCreator->createAndDispatch($printer, $reportDate);
        } catch (ReportPrinterUnavailableException) {
            $this->addFlash('error', 'The selected printer is not available for reports.');

            return $this->redirectToReportDate($reportDate);
        }

        $this->addFlash('success', sprintf(
            'Production report for %s queued for %s.',
            $reportDate->format('F j, Y'),
            $printer->getName(),
        ));

        return $this->redirectToReportDate($reportDate);
    }

    #[Route('/reports/production/pdf', name: 'production_report_pdf', methods: ['GET'])]
    public function previewPdf(Request $request, BakeryClock $bakeryClock, ProductionReportBuilder $reportBuilder, ProductionReportPdfRenderer $pdfRenderer): Response
    {
        return $this->pdfResponse($request, $bakeryClock, $reportBuilder, $pdfRenderer, false);
    }

    #[Route('/reports/production/pdf/download', name: 'production_report_pdf_download', methods: ['GET'])]
    public function downloadPdf(Request $request, BakeryClock $bakeryClock, ProductionReportBuilder $reportBuilder, ProductionReportPdfRenderer $pdfRenderer): Response
    {
        return $this->pdfResponse($request, $bakeryClock, $reportBuilder, $pdfRenderer, true);
    }

    private function pdfResponse(
        Request $request,
        BakeryClock $bakeryClock,
        ProductionReportBuilder $reportBuilder,
        ProductionReportPdfRenderer $pdfRenderer,
        bool $download,
    ): Response {
        try {
            $document = $pdfRenderer->render($reportBuilder->build($this->parseReportDate($request, $bakeryClock)));
        } catch (ProductionReportRenderingException $exception) {
            throw new ServiceUnavailableHttpException(null, 'The production report PDF is temporarily unavailable.', $exception);
        }

        $response = new Response($document->getContents());
        $response->headers->set('Content-Type', $document->mimeType);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $document->filename,
        ));

        return $response;
    }

    /** @param InputBag<string|int|float|bool|null>|null $parameters */
    private function parseReportDate(Request $request, BakeryClock $bakeryClock, ?InputBag $parameters = null): \DateTimeImmutable
    {
        $parameters ??= $request->query;
        if (!$parameters->has('date')) {
            return $bakeryClock->today();
        }

        $value = trim((string) $parameters->get('date'));
        $timezone = new \DateTimeZone($bakeryClock->getTimezoneName());
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('The report date must use the YYYY-MM-DD format.');
        }

        return $date;
    }

    private function redirectToReportDate(\DateTimeImmutable $reportDate): Response
    {
        return $this->redirectToRoute('production_report', ['date' => $reportDate->format('Y-m-d')]);
    }
}
