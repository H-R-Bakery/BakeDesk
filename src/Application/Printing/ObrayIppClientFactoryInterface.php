<?php

declare(strict_types=1);

namespace App\Application\Printing;

use obray\ipp\Job;
use obray\ipp\Printer;

interface ObrayIppClientFactoryInterface
{
    public function createPrinter(string $address): Printer;

    public function createJob(string $address, int|string $externalJobId): Job;
}
