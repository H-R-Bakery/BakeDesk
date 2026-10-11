<?php

declare(strict_types=1);

namespace App\Application\Cleanup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CleanupRetention
{
    private int $printJobRetentionDays;

    private int $orderRetentionDays;

    public function __construct(
        #[Autowire(env: 'BAKEDESK_PRINT_JOB_RETENTION_DAYS')]
        string $printJobRetentionDays,
        #[Autowire(env: 'BAKEDESK_ORDER_RETENTION_DAYS')]
        string $orderRetentionDays,
    ) {
        $this->printJobRetentionDays = self::parsePositiveInteger($printJobRetentionDays, 'BAKEDESK_PRINT_JOB_RETENTION_DAYS');
        $this->orderRetentionDays = self::parsePositiveInteger($orderRetentionDays, 'BAKEDESK_ORDER_RETENTION_DAYS');
    }

    public static function fromStrings(string $printJobRetentionDays, string $orderRetentionDays): self
    {
        return new self($printJobRetentionDays, $orderRetentionDays);
    }

    public function printJobCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub(new \DateInterval(sprintf('P%dD', $this->printJobRetentionDays)));
    }

    public function orderCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub(new \DateInterval(sprintf('P%dD', $this->orderRetentionDays)));
    }

    public function getPrintJobRetentionDays(): int
    {
        return $this->printJobRetentionDays;
    }

    public function getOrderRetentionDays(): int
    {
        return $this->orderRetentionDays;
    }

    private static function parsePositiveInteger(string $value, string $environmentVariable): int
    {
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer.', $environmentVariable));
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $parsed) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer within the supported integer range.', $environmentVariable));
        }

        return $parsed;
    }
}
