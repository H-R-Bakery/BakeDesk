<?php

declare(strict_types=1);

namespace App\Application\Production;

final class ProductionReportRenderingException extends \RuntimeException
{
    public static function forDate(string $date, \Throwable $previous): self
    {
        return new self(sprintf('Unable to render the production report for %s.', $date), 0, $previous);
    }
}
