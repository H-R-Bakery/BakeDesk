<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\Order;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class OrderReopener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BakeryClock $bakeryClock,
        private readonly OrderRealtimePublisher $realtimePublisher,
    ) {
    }

    public function reopen(Order $order): void
    {
        if (OrderStatus::COMPLETED !== $order->getStatus()) {
            throw new \LogicException('Only completed orders can be reopened.');
        }

        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $order
                ->setStatus(OrderStatus::OPEN)
                ->setUpdatedAt($this->bakeryClock->now());
        });

        $this->realtimePublisher->publishOrderUpdated($order);
    }
}
