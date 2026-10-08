<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Order\BakeryClock;
use App\Application\Production\ProductionReportBuilder;
use App\Application\Production\ProductionReportPdfRenderer;
use App\Application\Production\ProductionReportRenderingException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProductionReportController extends AbstractController
{
    #[Route('/reports/production', name: 'production_report', methods: ['GET'])]
    public function index(Request $request, BakeryClock $bakeryClock, ProductionReportBuilder $reportBuilder): Response
    {
        $report = $reportBuilder->build($this->parseReportDate($request, $bakeryClock));

        return $this->render('report/production.html.twig', [
            'report' => $report,
            'bakery_timezone' => $bakeryClock->getTimezoneName(),
        ]);
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

    private function parseReportDate(Request $request, BakeryClock $bakeryClock): \DateTimeImmutable
    {
        if (!$request->query->has('date')) {
            return $bakeryClock->today();
        }

        $value = trim((string) $request->query->get('date'));
        $timezone = new \DateTimeZone($bakeryClock->getTimezoneName());
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('The report date must use the YYYY-MM-DD format.');
        }

        return $date;
    }
}
