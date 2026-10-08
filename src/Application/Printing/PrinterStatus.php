<?php

declare(strict_types=1);

namespace App\Application\Printing;

final readonly class PrinterStatus
{
    /**
     * @param list<string>         $reasons
     * @param list<string>         $documentFormatsSupported
     * @param array<string, mixed> $rawAttributes
     */
    public function __construct(
        public PrinterState $state,
        public ?bool $acceptsJobs,
        public array $reasons = [],
        public array $documentFormatsSupported = [],
        public array $rawAttributes = [],
    ) {
    }

    public function supportsDocumentFormat(string $format): bool
    {
        return in_array($format, $this->documentFormatsSupported, true);
    }
}
