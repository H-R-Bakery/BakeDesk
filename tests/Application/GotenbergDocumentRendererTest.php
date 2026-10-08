<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Document\GotenbergDocumentRenderer;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

final class GotenbergDocumentRendererTest extends TestCase
{
    public function testLabelRequestUsesExactPaperSizeAndPrintsBackgrounds(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('sendRequest')
            ->with(self::callback(function (RequestInterface $request): bool {
                $body = (string) $request->getBody();

                self::assertSame('http://gotenberg:8833/forms/chromium/convert/html', (string) $request->getUri());
                self::assertStringContainsString('name="paperWidth"', $body);
                self::assertStringContainsString("\r\n\r\n4in\r\n", $body);
                self::assertStringContainsString('name="paperHeight"', $body);
                self::assertStringContainsString("\r\n\r\n6in\r\n", $body);
                self::assertStringContainsString('name="printBackground"', $body);

                return true;
            }))
            ->willReturn(new Response(200, [], '%PDF-1.7 test label'));

        $renderer = new GotenbergDocumentRenderer($httpClient, 'http://gotenberg:8833');

        self::assertSame('%PDF-1.7 test label', $renderer->renderHtmlToPdf('<!doctype html><html></html>'));
    }

    public function testProductionReportRequestUsesLetterPaperSize(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('sendRequest')
            ->with(self::callback(function (RequestInterface $request): bool {
                $body = (string) $request->getBody();

                self::assertStringContainsString('name="paperWidth"', $body);
                self::assertStringContainsString("\r\n\r\n8.5in\r\n", $body);
                self::assertStringContainsString('name="paperHeight"', $body);
                self::assertStringContainsString("\r\n\r\n11in\r\n", $body);

                return true;
            }))
            ->willReturn(new Response(200, [], '%PDF-1.7 test report'));

        $renderer = new GotenbergDocumentRenderer($httpClient, 'http://gotenberg:8833');

        self::assertSame(
            '%PDF-1.7 test report',
            $renderer->renderHtmlToPdfWithOptions('<!doctype html><html></html>', '8.5in', '11in', 'production-2026-10-09'),
        );
    }
}
