<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;
use App\Repository\PrinterRepository;

final class LabelPrinterResolver
{
    public function __construct(
        private readonly PrinterRepository $printerRepository,
    ) {
    }

    public function resolve(): Printer
    {
        $defaults = $this->printerRepository->findConfiguredLabelDefaults();

        if ([] === $defaults) {
            throw LabelPrinterConfigurationException::noDefault();
        }

        if (1 !== \count($defaults)) {
            throw LabelPrinterConfigurationException::ambiguous();
        }

        $printer = $defaults[0];
        if (!$printer->isActive() || !$printer->isForLabels()) {
            throw LabelPrinterConfigurationException::invalid();
        }

        return $printer;
    }
}
