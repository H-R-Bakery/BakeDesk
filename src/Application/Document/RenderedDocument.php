<?php

declare(strict_types=1);

namespace App\Application\Document;

final readonly class RenderedDocument
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public string $filename,
        private string $contents,
    ) {
    }

    public function getContents(): string
    {
        return $this->contents;
    }
}
