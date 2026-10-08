<?php

namespace App\Tests\Entity;

use App\Entity\Order;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use PHPUnit\Framework\TestCase;

final class PrinterPrintJobTest extends TestCase
{
    public function testPrinterDefaultsAreConservative(): void
    {
        $printer = new Printer();

        self::assertFalse($printer->isForLabels());
        self::assertFalse($printer->isForReports());
        self::assertTrue($printer->isActive());
        self::assertFalse($printer->isDefaultForLabels());
    }

    public function testPrinterCapabilitiesCanBeConfiguredIndependently(): void
    {
        $labelsOnly = (new Printer())->setForLabels(true);
        $reportsOnly = (new Printer())->setForReports(true);
        $both = (new Printer())->setForLabels(true)->setForReports(true);

        self::assertTrue($labelsOnly->isForLabels());
        self::assertFalse($labelsOnly->isForReports());
        self::assertFalse($reportsOnly->isForLabels());
        self::assertTrue($reportsOnly->isForReports());
        self::assertTrue($both->isForLabels());
        self::assertTrue($both->isForReports());
    }

    public function testDefaultLabelPrinterRequiresActiveLabelCapability(): void
    {
        $printer = new Printer();

        $this->expectException(\InvalidArgumentException::class);
        $printer->setDefaultForLabels(true);
    }

    public function testDefaultLabelPrinterCannotBeDeactivatedOrHaveItsLabelCapabilityRemoved(): void
    {
        $printer = (new Printer())
            ->setForLabels(true)
            ->setDefaultForLabels(true);

        try {
            $printer->setActive(false);
            self::fail('A default label printer must remain active.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $printer->setForLabels(false);
    }

    public function testPrintJobDefaultsToQueuedWithNoAttempts(): void
    {
        $printJob = new PrintJob();

        self::assertSame(PrintJobStatus::QUEUED, $printJob->getStatus());
        self::assertSame(0, $printJob->getAttemptCount());
    }

    public function testPrintJobCanReferenceAnOrderOrRemainUnassociated(): void
    {
        $printJob = (new PrintJob())->setOrder(new Order());
        $reportJob = (new PrintJob())->setDocumentType(PrintDocumentType::REPORT);

        self::assertInstanceOf(Order::class, $printJob->getOrder());
        self::assertNull($reportJob->getOrder());
    }

    public function testReportDateIsRepresentedAsAnImmutableDate(): void
    {
        $reportDate = new \DateTimeImmutable('2026-10-07');
        $printJob = (new PrintJob())->setReportDate($reportDate);

        self::assertSame('2026-10-07', $printJob->getReportDate()?->format('Y-m-d'));
        self::assertInstanceOf(\DateTimeImmutable::class, $printJob->getReportDate());
    }
}
