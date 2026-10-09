<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;
use App\Model\PrintDocumentType;

final class PrinterTestDocumentRenderingException extends \RuntimeException
{
    public static function forPrinter(Printer $printer, PrintDocumentType $documentType, \Throwable $previous): self
    {
        return new self(
            sprintf('The %s printer test document could not be generated.', strtolower($documentType->value)),
            0,
            $previous,
        );
    }
}
