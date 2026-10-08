<?php

declare(strict_types=1);

namespace App\Application\Printing;

final readonly class PrintJobStatusSnapshot
{
    /**
     * @param list<string>         $reasons
     * @param array<string, mixed> $rawAttributes
     */
    public function __construct(
        public PrintJobState $state,
        public array $reasons = [],
        public ?string $message = null,
        public array $rawAttributes = [],
    ) {
    }

    public function diagnosticMessage(): ?string
    {
        $parts = array_values(array_filter([$this->message, ...$this->reasons], static fn (?string $value): bool => null !== $value && '' !== $value));

        return [] === $parts ? null : implode('; ', $parts);
    }
}
