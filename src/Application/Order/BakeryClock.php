<?php

declare(strict_types=1);

namespace App\Application\Order;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BakeryClock
{
    private \DateTimeZone $timezone;

    private \DateTimeZone $utcTimezone;

    public function __construct(
        #[Autowire('%bakery_timezone%')]
        string $timezone,
    ) {
        $this->timezone = new \DateTimeZone($timezone);
        $this->utcTimezone = new \DateTimeZone('UTC');
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone);
    }

    public function today(): \DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }

    public function tomorrow(bool $morning = false): \DateTimeImmutable
    {
        if ($morning) {
            return $this->now()->setTime(8, 0)->modify('+1 day');
        }

        return $this->now()->setTime(0, 0)->modify('+1 day');
    }

    public function combineDateAndTime(\DateTimeImmutable $date, \DateTimeImmutable $time): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(
            $date->setTimezone($this->timezone)->format('Y-m-d').' '.$time->setTimezone($this->timezone)->format('H:i:s'),
            $this->timezone,
        ))->setTimezone($this->utcTimezone);
    }

    public function getTimezoneName(): string
    {
        return $this->timezone->getName();
    }
}
