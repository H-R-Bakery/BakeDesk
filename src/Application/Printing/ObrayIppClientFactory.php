<?php

declare(strict_types=1);

namespace App\Application\Printing;

use obray\ipp\Job;
use obray\ipp\Printer;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ObrayIppClientFactoryInterface::class)]
final class ObrayIppClientFactory implements ObrayIppClientFactoryInterface
{
    public function createPrinter(string $address): Printer
    {
        return new Printer($address);
    }

    public function createJob(string $address, int|string $externalJobId): Job
    {
        return new Job($address, $externalJobId);
    }
}
