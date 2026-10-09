<?php

declare(strict_types=1);

namespace App\Tests\Application\Printing;

use App\Application\Document\ConfigurableDocumentRendererInterface;
use App\Application\Order\BakeryClock;
use App\Application\Printing\PrinterClientInterface;
use App\Application\Printing\PrinterTestDocumentGenerator;
use App\Application\Printing\PrinterTestPrintService;
use App\Entity\Printer;
use App\Model\PrintDocumentType;
use League\Flysystem\FilesystemOperator;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class PrinterTestPrintServiceTest extends KernelTestCase
{
    public function testInactivePrinterIsRejectedBeforeRendering(): void
    {
        self::bootKernel();
        $renderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $renderer->expects(self::never())->method('renderHtmlToPdfWithOptions');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('write');
        $client = $this->createMock(PrinterClientInterface::class);
        $client->expects(self::never())->method('submitPdf');
        $service = $this->service($renderer, $storage, $client);
        $printer = (new Printer())->setName('Inactive Printer')->setActive(false)->setForReports(true);

        $this->expectExceptionMessage('is inactive');
        $service->submit($printer, PrintDocumentType::REPORT);
    }

    public function testWrongCapabilityIsRejectedBeforeRendering(): void
    {
        self::bootKernel();
        $renderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $renderer->expects(self::never())->method('renderHtmlToPdfWithOptions');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('write');
        $client = $this->createMock(PrinterClientInterface::class);
        $client->expects(self::never())->method('submitPdf');
        $service = $this->service($renderer, $storage, $client);
        $printer = (new Printer())->setName('Report Only Printer')->setActive(true)->setForReports(true);

        $this->expectExceptionMessage('not enabled for labels');
        $service->submit($printer, PrintDocumentType::LABEL);
    }

    public function testWrongReportCapabilityIsRejectedBeforeRendering(): void
    {
        self::bootKernel();
        $renderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $renderer->expects(self::never())->method('renderHtmlToPdfWithOptions');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('write');
        $client = $this->createMock(PrinterClientInterface::class);
        $client->expects(self::never())->method('submitPdf');
        $service = $this->service($renderer, $storage, $client);
        $printer = (new Printer())->setName('Label Only Printer')->setActive(true)->setForLabels(true);

        $this->expectExceptionMessage('not enabled for reports');
        $service->submit($printer, PrintDocumentType::REPORT);
    }

    private function service(
        ConfigurableDocumentRendererInterface $renderer,
        FilesystemOperator $storage,
        PrinterClientInterface $client,
    ): PrinterTestPrintService {
        return new PrinterTestPrintService(
            new PrinterTestDocumentGenerator(
                self::getContainer()->get(Environment::class),
                $renderer,
                $storage,
                new BakeryClock('America/Indiana/Indianapolis'),
                new NullLogger(),
            ),
            $client,
            'BakeDesk',
        );
    }
}
