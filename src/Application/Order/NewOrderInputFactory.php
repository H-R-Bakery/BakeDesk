<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Entity\Order;
use App\Form\Model\NewOrderData;
use App\Repository\CustomerRepository;

final class NewOrderInputFactory
{
    public function __construct(
        private OrderCreator $orderCreator,
        private BakeryClock $bakeryClock,
        private CustomerRepository $customerRepository,
    ) {
    }

    public function create(NewOrderData $data): Order
    {
        if (null === $data->customerPhone || null === $data->employee || null === $data->pickupDate || null === $data->pickupTime) {
            throw new \LogicException('A valid new order form is required.');
        }

        $items = [];
        foreach ($data->items as $sortOrder => $item) {
            if (null === $item->productType || null === $item->quantity || null === $item->unit) {
                throw new \LogicException('A valid order item is required.');
            }

            $items[] = new OrderItemInput(
                productType: $item->productType,
                quantity: $item->quantity,
                unit: $item->unit,
                description: trim($item->description),
                sortOrder: $sortOrder,
            );
        }

        $selectedCustomer = null === $data->customerId ? null : $this->customerRepository->find($data->customerId);

        return $this->orderCreator->create(new OrderInput(
            customerName: trim($data->customerName),
            customerPhone: $data->customerPhone,
            employee: $data->employee,
            pickupAt: $this->bakeryClock->combineDateAndTime($data->pickupDate, $data->pickupTime),
            orderedAt: $this->bakeryClock->now(),
            paid: $data->paid,
            notes: null === $data->notes || '' === trim($data->notes) ? null : trim($data->notes),
            items: $items,
            customer: $selectedCustomer,
        ));
    }
}
