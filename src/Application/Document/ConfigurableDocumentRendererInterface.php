<?php

declare(strict_types=1);

namespace App\Application\Document;

interface ConfigurableDocumentRendererInterface extends DocumentRendererInterface
{
    public function renderHtmlToPdfWithOptions(string $html, string $paperWidth, string $paperHeight, string $outputFilename): string;
}
