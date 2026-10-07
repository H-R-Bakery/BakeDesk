<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Entity\Employee;
use libphonenumber\PhoneNumber;

final readonly class OrderInput
{
    /**
     * @param list<OrderItemInput> $items
     */
    public function __construct(
        public string $orderNumber,
        public string $customerName,
        public PhoneNumber $customerPhone,
        public Employee $employee,
        public \DateTimeImmutable $pickupAt,
        public \DateTimeImmutable $orderedAt,
        public bool $paid = false,
        public ?string $notes = null,
        public array $items = [],
    ) {
    }
}
