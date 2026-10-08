<?php

declare(strict_types=1);

namespace App\Tests\Application\Printing;

use App\Application\Printing\IppPrinterClient;
use App\Application\Printing\IppTransportInterface;
use App\Application\Printing\PrinterState;
use App\Application\Printing\PrinterStatus;
use App\Application\Printing\PrintSubmission;
use App\Entity\Printer;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class IppPrinterClientTest extends TestCase
{
    public function testPdfIsLoadedFromFlysystemAndSubmittedToConfiguredPrinter(): void
    {
        $printer = (new Printer())
            ->setName('Development IPP printer')
            ->setAddress('ipp://printer.example/ipp/print')
            ->setForLabels(true);
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('read')->with('labels/order-123.pdf')->willReturn('%PDF-1.7 label');
        $transport = $this->createMock(IppTransportInterface::class);
        $transport->expects(self::once())->method('getPrinterStatus')->with($printer)->willReturn(new PrinterStatus(
            PrinterState::IDLE,
            true,
            documentFormatsSupported: ['application/pdf'],
        ));
        $transport->expects(self::once())
            ->method('submitPdf')
            ->with($printer, '%PDF-1.7 label', 'BakeDesk Order #1234')
            ->willReturn(new PrintSubmission('17'));

        $submission = (new IppPrinterClient($transport, $storage))->submitPdf($printer, 'labels/order-123.pdf', 'BakeDesk Order #1234');

        self::assertSame('17', $submission->externalJobId);
    }

    public function testMissingFlysystemDocumentDoesNotCallIpp(): void
    {
        $printer = (new Printer())->setAddress('ipp://printer.example/ipp/print')->setForLabels(true);
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('read')->willThrowException(new \RuntimeException('missing'));
        $transport = $this->createMock(IppTransportInterface::class);
        $transport->expects(self::never())->method('getPrinterStatus');
        $transport->expects(self::never())->method('submitPdf');

        $this->expectException(\RuntimeException::class);
        (new IppPrinterClient($transport, $storage))->submitPdf($printer, 'missing.pdf', 'BakeDesk Order #1234');
    }

    public function testUnsupportedPdfCapabilityPreventsSubmission(): void
    {
        $printer = (new Printer())->setAddress('ipp://printer.example/ipp/print')->setForLabels(true);
        $storage = $this->createStub(FilesystemOperator::class);
        $storage->method('read')->willReturn('%PDF-1.7 label');
        $transport = $this->createMock(IppTransportInterface::class);
        $transport->expects(self::once())->method('getPrinterStatus')->willReturn(new PrinterStatus(
            PrinterState::IDLE,
            true,
            documentFormatsSupported: ['text/plain'],
        ));
        $transport->expects(self::never())->method('submitPdf');

        $this->expectException(\RuntimeException::class);
        (new IppPrinterClient($transport, $storage))->submitPdf($printer, 'label.pdf', 'BakeDesk Order #1234');
    }

    public function testInactivePrinterCannotReceiveSubmission(): void
    {
        $printer = (new Printer())->setAddress('ipp://printer.example/ipp/print')->setForLabels(true)->setActive(false);
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('read');
        $transport = $this->createMock(IppTransportInterface::class);
        $transport->expects(self::never())->method('submitPdf');

        $this->expectException(\RuntimeException::class);
        (new IppPrinterClient($transport, $storage))->submitPdf($printer, 'label.pdf', 'BakeDesk Order #1234');
    }
}
