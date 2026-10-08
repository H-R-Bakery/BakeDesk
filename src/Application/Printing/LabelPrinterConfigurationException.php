<?php

declare(strict_types=1);

namespace App\Application\Printing;

final class LabelPrinterConfigurationException extends \RuntimeException
{
    public static function noDefault(): self
    {
        return new self('No default label printer is configured.');
    }

    public static function ambiguous(): self
    {
        return new self('More than one default label printer is configured.');
    }

    public static function invalid(): self
    {
        return new self('The default label printer must be active and support labels.');
    }
}
