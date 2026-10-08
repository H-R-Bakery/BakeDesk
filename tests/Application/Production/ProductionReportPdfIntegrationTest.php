<?php

declare(strict_types=1);

namespace App\Tests\Application\Production;

use App\Application\Document\GotenbergDocumentRenderer;
use App\Application\Order\BakeryClock;
use App\Application\Production\ProductionReport;
use App\Application\Production\ProductionReportLine;
use App\Application\Production\ProductionReportPdfRenderer;
use App\Application\Production\ProductionReportSection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Twig\Environment;

final class ProductionReportPdfIntegrationTest extends KernelTestCase
{
    public function testProductionReportIsOnePageLetterPdfWithReadableContent(): void
    {
        self::bootKernel();
        $this->skipIfGotenbergIsUnavailable();

        $renderer = new ProductionReportPdfRenderer(
            self::getContainer()->get(Environment::class),
            self::getContainer()->get(GotenbergDocumentRenderer::class),
            $this->createStub(FilesystemOperator::class),
            new NullLogger(),
            new BakeryClock('America/Indiana/Indianapolis'),
        );

        $pdf = $renderer->render($this->report())->getContents();
        $pdfInfo = $this->inspectPdf($pdf);
        $text = $this->extractText($pdf);

        self::assertMatchesRegularExpression('/^Pages:\s+1$/m', $pdfInfo);
        self::assertMatchesRegularExpression('/^Page size:\s+612 x 792 pts(?: \(letter\))?$/m', $pdfInfo);
        self::assertStringContainsString('Production Report', $text);
        self::assertStringContainsString('TOTAL TO MAKE: 26 EACH', $text);
        self::assertStringContainsString('TOTAL TO MAKE: 22 EACH', $text);
        self::assertStringContainsString('Snapshot Customer', $text);
        self::assertStringContainsString('1 Dozen', $text);
        self::assertStringContainsString('1 Tray', $text);
    }

    private function report(): ProductionReport
    {
        $timezone = new \DateTimeZone('America/Indiana/Indianapolis');

        return new ProductionReport(
            new \DateTimeImmutable('2026-10-09', $timezone),
            [
                new ProductionReportSection('Donuts', '26', [
                    new ProductionReportLine(
                        '1001',
                        new \DateTimeImmutable('2026-10-09 08:00:00', $timezone),
                        'Snapshot Customer',
                        '1',
                        'Dozen',
                        '12',
                        'Glazed',
                    ),
                    new ProductionReportLine(
                        '1004',
                        new \DateTimeImmutable('2026-10-09 09:30:00', $timezone),
                        'Completed Customer',
                        '14',
                        'Each',
                        '14',
                        'Assorted',
                    ),
                ]),
                new ProductionReportSection('Cookies', '22', [
                    new ProductionReportLine(
                        '1001',
                        new \DateTimeImmutable('2026-10-09 08:00:00', $timezone),
                        'Snapshot Customer',
                        '2',
                        'Each',
                        '2',
                        'Chocolate Chip',
                    ),
                    new ProductionReportLine(
                        '1004',
                        new \DateTimeImmutable('2026-10-09 09:30:00', $timezone),
                        'Completed Customer',
                        '1',
                        'Tray',
                        '20',
                        'Sugar',
                    ),
                ]),
            ],
        );
    }

    private function skipIfGotenbergIsUnavailable(): void
    {
        $baseUrl = $_ENV['GOTENBERG_BASE_URL'] ?? 'http://localhost:18833';
        $handle = curl_init($baseUrl.'/health');
        if (false === $handle) {
            self::markTestSkipped('Gotenberg is unavailable.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (false === $response || 200 !== $status) {
            self::markTestSkipped('Gotenberg is unavailable.');
        }
    }

    private function inspectPdf(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bakedesk-production-');
        self::assertNotFalse($path);
        file_put_contents($path, $pdf);

        try {
            $process = new Process(['pdfinfo', $path]);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            unlink($path);
        }
    }

    private function extractText(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bakedesk-production-text-');
        self::assertNotFalse($path);
        file_put_contents($path, $pdf);

        try {
            $process = new Process(['pdftotext', $path, '-']);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            unlink($path);
        }
    }
}
