<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Application\Customer\CustomerResolver;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrderCreator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CustomerResolver $customerResolver,
        private OrderRepository $orderRepository,
    ) {
    }

    public function create(OrderInput $input): Order
    {
        return $this->entityManager->wrapInTransaction(function () use ($input): Order {
            $customer = $this->customerResolver->resolve($input->customerName, $input->customerPhone);

            $order = (new Order())
                ->setOrderNumber($input->orderNumber)
                ->setCustomer($customer)
                ->setCustomerName($input->customerName)
                ->setCustomerPhone($input->customerPhone)
                ->setEmployee($input->employee)
                ->setPickupAt($input->pickupAt)
                ->setOrderedAt($input->orderedAt)
                ->setPaid($input->paid)
                ->setNotes($input->notes);

            foreach ($input->items as $itemInput) {
                $order->addItem(
                    (new OrderItem())
                        ->setProductType($itemInput->productType)
                        ->setQuantity($itemInput->quantity)
                        ->setUnit($itemInput->unit)
                        ->setDescription($itemInput->description)
                        ->setSortOrder($itemInput->sortOrder),
                );
            }

            $this->orderRepository->save($order);

            return $order;
        });
    }
}
