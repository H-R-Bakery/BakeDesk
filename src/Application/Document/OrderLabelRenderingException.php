<?php

declare(strict_types=1);

namespace App\Application\Document;

final class OrderLabelRenderingException extends \RuntimeException
{
    public static function forOrder(string $orderNumber, \Throwable $previous): self
    {
        return new self(sprintf('Unable to render the label for order %s.', $orderNumber), 0, $previous);
    }
}
