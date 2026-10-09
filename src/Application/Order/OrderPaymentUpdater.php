<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\Order;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class OrderPaymentUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BakeryClock $bakeryClock,
        private readonly OrderRealtimePublisher $realtimePublisher,
    ) {
    }

    public function markPaid(Order $order): bool
    {
        return $this->update($order, true);
    }

    public function markNotPaid(Order $order): bool
    {
        return $this->update($order, false);
    }

    private function update(Order $order, bool $paid): bool
    {
        if (!\in_array($order->getStatus(), [OrderStatus::OPEN, OrderStatus::COMPLETED], true)) {
            throw new \LogicException('Cancelled orders cannot have their payment status changed.');
        }

        if ($order->isPaid() === $paid) {
            return false;
        }

        $this->entityManager->wrapInTransaction(function () use ($order, $paid): void {
            $order
                ->setPaid($paid)
                ->setUpdatedAt($this->bakeryClock->now());
        });

        $this->realtimePublisher->publishOrderUpdated($order);

        return true;
    }
}
