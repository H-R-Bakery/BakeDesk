<?php

declare(strict_types=1);

namespace App\Application\Document;

interface DocumentRendererInterface
{
    public function renderHtmlToPdf(string $html): string;
}
