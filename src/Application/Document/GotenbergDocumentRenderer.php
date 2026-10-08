<?php

declare(strict_types=1);

namespace App\Application\Document;

use Gotenberg\Gotenberg;
use Gotenberg\Stream;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(DocumentRendererInterface::class)]
#[AsAlias(ConfigurableDocumentRendererInterface::class)]
final class GotenbergDocumentRenderer implements ConfigurableDocumentRendererInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        #[Autowire(env: 'GOTENBERG_BASE_URL')]
        private readonly string $baseUrl,
    ) {
    }

    public function renderHtmlToPdf(string $html): string
    {
        return $this->renderHtmlToPdfWithOptions($html, '4in', '6in', 'order-label');
    }

    public function renderHtmlToPdfWithOptions(string $html, string $paperWidth, string $paperHeight, string $outputFilename): string
    {
        $request = Gotenberg::chromium($this->baseUrl)
            ->pdf()
            ->outputFilename($outputFilename)
            ->paperSize($paperWidth, $paperHeight)
            ->margins('0', '0', '0', '0')
            ->preferCssPageSize()
            ->printBackground()
            ->html(Stream::string('index.html', $html));

        return Gotenberg::send($request, $this->httpClient)->getBody()->getContents();
    }
}
