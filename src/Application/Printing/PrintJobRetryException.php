<?php

declare(strict_types=1);

namespace App\Application\Printing;

final class PrintJobRetryException extends \RuntimeException
{
    public static function onlyFailedLabels(): self
    {
        return new self('Only failed label print jobs can be retried.');
    }

    public static function unavailablePrinter(): self
    {
        return new self('This print job cannot be retried because its printer is no longer available.');
    }

    public static function missingOrder(): self
    {
        return new self('This label print job cannot be retried because its order is unavailable.');
    }

    public static function cancelledOrder(): self
    {
        return new self('Cancelled orders cannot produce new labels.');
    }

    public static function incompletePackageContext(): self
    {
        return new self('This historical label print job does not contain enough package information to retry safely.');
    }
}
