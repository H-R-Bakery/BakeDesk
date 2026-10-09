<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;

final readonly class PrinterDiagnosticsService
{
    public function __construct(
        private PrinterClientInterface $printerClient,
    ) {
    }

    public function check(Printer $printer): PrinterStatus
    {
        return $this->printerClient->getPrinterStatus($printer);
    }

    public function stateLabel(PrinterState $state): string
    {
        return match ($state) {
            PrinterState::IDLE => 'Idle',
            PrinterState::PROCESSING => 'Processing',
            PrinterState::STOPPED => 'Stopped',
            PrinterState::UNKNOWN => 'Unknown',
        };
    }

    public function stateBadgeClass(PrinterState $state): string
    {
        return match ($state) {
            PrinterState::IDLE => 'success',
            PrinterState::PROCESSING => 'info',
            PrinterState::STOPPED => 'danger',
            PrinterState::UNKNOWN => 'secondary',
        };
    }

    public function booleanLabel(?bool $value): string
    {
        return match ($value) {
            true => 'Yes',
            false => 'No',
            null => 'Unknown',
        };
    }

    /** @return list<string> */
    public function reasonLabels(PrinterStatus $status): array
    {
        $reasons = array_values(array_filter(
            $status->reasons,
            static fn (string $reason): bool => '' !== trim($reason) && 'none' !== strtolower(trim($reason)),
        ));

        return [] === $reasons ? ['None reported'] : $reasons;
    }

    /** @return array<string, mixed> */
    public function safeRawAttributes(PrinterStatus $status): array
    {
        return $this->sanitize($status->rawAttributes);
    }

    public function safeAddress(Printer $printer): string
    {
        return preg_replace(
            '#^(ipp|ipps|http|https)://[^/@]+@#i',
            '$1://',
            $printer->getAddress(),
        ) ?? $printer->getAddress();
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $attributes): array
    {
        $sanitized = [];
        foreach ($attributes as $key => $value) {
            $sanitized[(string) $key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_scalar($value) || null === $value) {
            return $value;
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $nestedValue) {
                $sanitized[(string) $key] = $this->sanitizeValue($nestedValue);
            }

            return $sanitized;
        }

        return sprintf('[%s]', get_debug_type($value));
    }
}
