<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Entity\Order;
use App\Form\Model\NewOrderData;
use App\Form\Model\NewOrderItemData;

final class OrderFormDataFactory
{
    public function __construct(
        private BakeryClock $bakeryClock,
    ) {
    }

    public function fromOrder(Order $order): NewOrderData
    {
        $pickupAt = $order->getPickupAt();
        $customerPhone = $order->getCustomerPhone();
        $user = $order->getUser();

        if (null === $pickupAt || null === $customerPhone || null === $user) {
            throw new \LogicException('An order must have complete details before it can be edited.');
        }

        $pickupAt = $pickupAt->setTimezone(new \DateTimeZone($this->bakeryClock->getTimezoneName()));

        $data = new NewOrderData();
        $data->customerName = $order->getCustomerName();
        $data->customerId = $order->getCustomer()?->getId();
        $data->customerPhone = clone $customerPhone;
        $data->user = $user;
        $data->pickupDate = $pickupAt;
        $data->pickupTime = $pickupAt;
        $data->paid = $order->isPaid();
        $data->notes = $order->getNotes();
        $data->items = [];

        foreach ($order->getItems() as $orderItem) {
            $item = new NewOrderItemData();
            $item->id = $orderItem->getId();
            $item->productType = $orderItem->getProductType();
            $item->quantity = $orderItem->getQuantity();
            $item->unit = $orderItem->getUnit();
            $item->description = $orderItem->getDescription();
            $data->items[] = $item;
        }

        return $data;
    }
}
