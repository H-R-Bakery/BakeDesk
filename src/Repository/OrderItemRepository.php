<?php

namespace App\Repository;

use App\Entity\OrderItem;

/** @extends AbstractServiceEntityRepository<OrderItem> */
class OrderItemRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return OrderItem::class;
    }
}
