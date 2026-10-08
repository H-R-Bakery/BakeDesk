<?php

declare(strict_types=1);

namespace App\Application\Printing;

final class PrinterStatusException extends \RuntimeException
{
    public static function unavailable(\Throwable $previous): self
    {
        return new self('The printer status could not be retrieved.', 0, $previous);
    }
}
