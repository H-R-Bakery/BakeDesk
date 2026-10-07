<?php

namespace App\Repository;

use App\Entity\Order;

class OrderRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return Order::class;
    }
}
