<?php

declare(strict_types=1);

namespace App\Tests\Application\Production;

use App\Application\Document\ConfigurableDocumentRendererInterface;
use App\Application\Order\BakeryClock;
use App\Application\Production\ProductionReport;
use App\Application\Production\ProductionReportLine;
use App\Application\Production\ProductionReportPdfRenderer;
use App\Application\Production\ProductionReportSection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ProductionReportPdfRendererTest extends KernelTestCase
{
    public function testReportUsesLetterGotenbergOptionsAndStoresThePdf(): void
    {
        self::bootKernel();
        $renderedHtml = '';
        $documentRenderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $documentRenderer
            ->expects(self::once())
            ->method('renderHtmlToPdfWithOptions')
            ->with(
                self::callback(function (string $html) use (&$renderedHtml): bool {
                    $renderedHtml = $html;

                    return str_contains($html, 'TOTAL TO MAKE: 26 EACH')
                        && str_contains($html, 'Snapshot Customer')
                        && str_contains($html, 'Glazed');
                }),
                '8.5in',
                '11in',
                'production-2026-10-09',
            )
            ->willReturn('%PDF-1.7 production report');

        $storage = $this->createMock(FilesystemOperator::class);
        $storage
            ->expects(self::once())
            ->method('write')
            ->with(
                'reports/production/2026/10/production-2026-10-09.pdf',
                '%PDF-1.7 production report',
            );

        $renderer = new ProductionReportPdfRenderer(
            self::getContainer()->get(Environment::class),
            $documentRenderer,
            $storage,
            new NullLogger(),
            new BakeryClock('America/Indiana/Indianapolis'),
        );
        $document = $renderer->render($this->report());

        self::assertSame('application/pdf', $document->mimeType);
        self::assertSame('production-2026-10-09.pdf', $document->filename);
        self::assertSame('%PDF-1.7 production report', $document->getContents());
        self::assertStringContainsString('Letter', $renderedHtml);
    }

    private function report(): ProductionReport
    {
        $timezone = new \DateTimeZone('America/Indiana/Indianapolis');
        $line = new ProductionReportLine(
            orderNumber: '1001',
            pickupAt: new \DateTimeImmutable('2026-10-09 08:00:00', $timezone),
            customerName: 'Snapshot Customer',
            quantity: '1',
            unitName: 'Dozen',
            eachQuantity: '12',
            description: 'Glazed',
        );

        return new ProductionReport(
            new \DateTimeImmutable('2026-10-09', $timezone),
            [new ProductionReportSection('Donuts', '26', [$line])],
        );
    }
}
