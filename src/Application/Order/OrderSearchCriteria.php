<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Model\OrderStatus;

final readonly class OrderSearchCriteria
{
    public function __construct(
        public string $query = '',
        public ?\DateTimeImmutable $pickupDate = null,
        public ?OrderStatus $status = null,
        public bool $statusProvided = false,
        public ?int $employeeId = null,
        public ?\DateTimeImmutable $upcomingFrom = null,
    ) {
    }

    public function isDefaultUpcoming(): bool
    {
        return '' === $this->query
            && null === $this->pickupDate
            && !$this->statusProvided
            && null === $this->employeeId;
    }
}
