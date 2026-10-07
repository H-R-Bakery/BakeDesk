<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Entity\Order;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class OrderCanceller
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BakeryClock $bakeryClock,
    ) {
    }

    public function cancel(Order $order): void
    {
        if (OrderStatus::OPEN !== $order->getStatus()) {
            throw new \LogicException('Only open orders can be cancelled.');
        }

        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $order
                ->setStatus(OrderStatus::CANCELLED)
                ->setUpdatedAt($this->bakeryClock->now());
        });
    }
}
