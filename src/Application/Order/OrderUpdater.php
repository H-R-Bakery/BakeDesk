<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Application\Customer\CustomerResolver;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class OrderUpdater
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CustomerResolver $customerResolver,
        private BakeryClock $bakeryClock,
    ) {
    }

    public function update(Order $order, OrderInput $input): Order
    {
        if (OrderStatus::OPEN !== $order->getStatus()) {
            throw new \LogicException('Only open orders can be edited.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($order, $input): Order {
            $customer = $this->customerResolver->resolve($input->customerName, $input->customerPhone, $input->customer);

            $order
                ->setCustomer($customer)
                ->setCustomerName($input->customerName)
                ->setCustomerPhone($input->customerPhone)
                ->setEmployee($input->employee)
                ->setPickupAt($input->pickupAt)
                ->setPaid($input->paid)
                ->setNotes($input->notes)
                ->setUpdatedAt($this->bakeryClock->now());

            $existingItems = [];
            foreach ($order->getItems() as $item) {
                if (null !== $item->getId()) {
                    $existingItems[$item->getId()] = $item;
                }
            }

            $retainedItemIds = [];
            foreach ($input->items as $itemInput) {
                $item = null !== $itemInput->id ? ($existingItems[$itemInput->id] ?? null) : null;
                if (null === $item) {
                    $item = new OrderItem();
                    $order->addItem($item);
                }

                $item
                    ->setProductType($itemInput->productType)
                    ->setQuantity($itemInput->quantity)
                    ->setUnit($itemInput->unit)
                    ->setDescription($itemInput->description)
                    ->setSortOrder($itemInput->sortOrder);

                if (null !== $item->getId()) {
                    $retainedItemIds[$item->getId()] = true;
                }
            }

            foreach ($existingItems as $id => $item) {
                if (!isset($retainedItemIds[$id])) {
                    $order->removeItem($item);
                }
            }

            return $order;
        });
    }
}
