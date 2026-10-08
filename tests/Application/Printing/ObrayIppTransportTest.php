<?php

declare(strict_types=1);

namespace App\Tests\Application\Printing;

use App\Application\Printing\ObrayIppClientFactoryInterface;
use App\Application\Printing\ObrayIppTransport;
use App\Application\Printing\PrinterState;
use App\Application\Printing\PrintJobState;
use App\Entity\Printer;
use obray\ipp\enums\JobState as ObrayJobState;
use obray\ipp\enums\PrinterState as ObrayPrinterState;
use obray\ipp\Job;
use obray\ipp\JobAttributes;
use obray\ipp\Printer as IppPrinter;
use obray\ipp\PrinterAttributes;
use obray\ipp\transport\IPPPayload;
use PHPUnit\Framework\TestCase;

final class ObrayIppTransportTest extends TestCase
{
    public function testPdfFormatAndJobNameAreSentAndExternalIdIsMapped(): void
    {
        $ippPrinter = $this->createMock(IppPrinter::class);
        $ippPrinter
            ->expects(self::once())
            ->method('printJob')
            ->with('%PDF-1.7 label', 1, self::callback(static function (array $attributes): bool {
                return 'application/pdf' === $attributes['document-format'] && 'BakeDesk Order #1234' === $attributes['job-name'];
            }))
            ->willReturn($this->jobPayload(17, ObrayJobState::PENDING));
        $factory = $this->createMock(ObrayIppClientFactoryInterface::class);
        $factory->expects(self::once())->method('createPrinter')->with('ipp://printer.example/ipp/print')->willReturn($ippPrinter);

        $submission = (new ObrayIppTransport($factory))->submitPdf(
            (new Printer())->setAddress('ipp://printer.example/ipp/print'),
            '%PDF-1.7 label',
            'BakeDesk Order #1234',
        );

        self::assertSame('17', $submission->externalJobId);
        self::assertSame(PrintJobState::PENDING, $submission->initialStatus?->state);
    }

    public function testPrinterAndJobAttributesAreNormalized(): void
    {
        $ippPrinter = $this->createStub(IppPrinter::class);
        $ippPrinter->method('getPrinterAttributes')->willReturn($this->printerPayload());
        $job = $this->createMock(Job::class);
        $job->expects(self::once())->method('getJobAttributes')->willReturn($this->jobPayload(17, ObrayJobState::COMPLETED));
        $factory = $this->createMock(ObrayIppClientFactoryInterface::class);
        $factory->method('createPrinter')->willReturn($ippPrinter);
        $factory->expects(self::once())->method('createJob')->with('ipp://printer.example/ipp/print', 17)->willReturn($job);
        $transport = new ObrayIppTransport($factory);
        $printer = (new Printer())->setAddress('ipp://printer.example/ipp/print');

        $status = $transport->getPrinterStatus($printer);
        $jobStatus = $transport->getJobStatus($printer, '17');

        self::assertSame(PrinterState::PROCESSING, $status->state);
        self::assertTrue($status->acceptsJobs);
        self::assertSame(['application/pdf'], $status->documentFormatsSupported);
        self::assertSame(PrintJobState::COMPLETED, $jobStatus->state);
    }

    private function printerPayload(): IPPPayload
    {
        $attributes = new PrinterAttributes();
        $attributes->set('printer-state', ObrayPrinterState::processing);
        $attributes->set('printer-is-accepting-jobs', true);
        $attributes->set('printer-state-reasons', ['none']);
        $attributes->set('document-format-supported', ['application/pdf']);
        $payload = new IPPPayload();
        $payload->printerAttributes = [$attributes];

        return $payload;
    }

    private function jobPayload(int $jobId, int $state): IPPPayload
    {
        $attributes = new JobAttributes();
        $attributes->set('job-id', $jobId);
        $attributes->set('job-state', $state);
        $attributes->set('job-state-reasons', ['job-completed-successfully']);
        $attributes->set('job-state-message', 'Test status');
        $payload = new IPPPayload();
        $payload->jobAttributes = [$attributes];

        return $payload;
    }
}
