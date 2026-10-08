<?php

declare(strict_types=1);

namespace App\Application\Printing;

final class PrinterSubmissionException extends \RuntimeException
{
    public static function rejected(string $message): self
    {
        return new self($message);
    }

    public static function outcomeUnknown(\Throwable $previous): self
    {
        return new self('The printer submission outcome is unknown; do not resubmit this label automatically.', 0, $previous);
    }
}
