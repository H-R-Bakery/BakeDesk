<?php

declare(strict_types=1);

namespace App\Application\Printing;

final class ReportPrinterUnavailableException extends \RuntimeException
{
    public static function forPrinter(): self
    {
        return new self('The selected printer is not available for reports.');
    }
}
