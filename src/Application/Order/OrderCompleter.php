<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\Order;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class OrderCompleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BakeryClock $bakeryClock,
        private readonly OrderRealtimePublisher $realtimePublisher,
    ) {
    }

    public function complete(Order $order): void
    {
        if (OrderStatus::OPEN !== $order->getStatus()) {
            throw new \LogicException('Only open orders can be completed.');
        }

        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $order
                ->setStatus(OrderStatus::COMPLETED)
                ->setUpdatedAt($this->bakeryClock->now());
        });

        $this->realtimePublisher->publishOrderUpdated($order);
    }
}
