<?php

declare(strict_types=1);

namespace App\Tests\Application\Printing;

use App\Application\Document\ConfigurableDocumentRendererInterface;
use App\Application\Document\RenderedDocument;
use App\Application\Order\BakeryClock;
use App\Application\Printing\PrinterTestDocumentGenerator;
use App\Entity\Printer;
use App\Model\PrintDocumentType;
use League\Flysystem\FilesystemOperator;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class PrinterTestDocumentGeneratorTest extends KernelTestCase
{
    public function testLabelTestUsesFourBySixAndPrivateDiagnosticStorage(): void
    {
        self::bootKernel();
        $printer = $this->printer(41, 'Front Label Printer');
        $documentRenderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $documentRenderer
            ->expects(self::once())
            ->method('renderHtmlToPdfWithOptions')
            ->with(
                self::callback(static fn (string $html): bool => str_contains($html, 'LABEL PRINTER TEST') && str_contains($html, 'Front Label Printer')),
                '4in',
                '6in',
                self::matchesRegularExpression('/^label-test-\d{8}-\d{6}-\d{6}$/'),
            )
            ->willReturn('%PDF-1.7 label test');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage
            ->expects(self::once())
            ->method('write')
            ->with(
                self::matchesRegularExpression('#^diagnostics/printers/41/label-test-\d{8}-\d{6}-\d{6}\.pdf$#'),
                '%PDF-1.7 label test',
            );

        $document = (new PrinterTestDocumentGenerator(
            self::getContainer()->get(Environment::class),
            $documentRenderer,
            $storage,
            new BakeryClock('America/Indiana/Indianapolis'),
            new NullLogger(),
        ))->render($printer, PrintDocumentType::LABEL);

        self::assertInstanceOf(RenderedDocument::class, $document);
        self::assertStringStartsWith('%PDF-', $document->getContents());
        self::assertSame('application/pdf', $document->mimeType);
    }

    public function testReportTestUsesLetterAndSeparatePrivateDiagnosticStorage(): void
    {
        self::bootKernel();
        $printer = $this->printer(42, 'Office Report Printer');
        $documentRenderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $documentRenderer
            ->expects(self::once())
            ->method('renderHtmlToPdfWithOptions')
            ->with(
                self::callback(static fn (string $html): bool => str_contains($html, 'REPORT PRINTER TEST') && str_contains($html, 'Office Report Printer')),
                '8.5in',
                '11in',
                self::matchesRegularExpression('/^report-test-\d{8}-\d{6}-\d{6}$/'),
            )
            ->willReturn('%PDF-1.7 report test');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage
            ->expects(self::once())
            ->method('write')
            ->with(
                self::matchesRegularExpression('#^diagnostics/printers/42/report-test-\d{8}-\d{6}-\d{6}\.pdf$#'),
                '%PDF-1.7 report test',
            );

        $document = (new PrinterTestDocumentGenerator(
            self::getContainer()->get(Environment::class),
            $documentRenderer,
            $storage,
            new BakeryClock('America/Indiana/Indianapolis'),
            new NullLogger(),
        ))->render($printer, PrintDocumentType::REPORT);

        self::assertStringStartsWith('%PDF-', $document->getContents());
        self::assertSame('application/pdf', $document->mimeType);
    }

    private function printer(int $id, string $name): Printer
    {
        $printer = (new Printer())
            ->setName($name)
            ->setAddress('ipp://printer.example/ipp/print')
            ->setActive(true);
        (new \ReflectionProperty(Printer::class, 'id'))->setValue($printer, $id);

        return $printer;
    }
}
