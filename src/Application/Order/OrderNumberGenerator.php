<?php

declare(strict_types=1);

namespace App\Application\Order;

use Doctrine\DBAL\Connection;

final class OrderNumberGenerator
{
    public const SEQUENCE_NAME = 'bakery_order_number_seq';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function next(): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT nextval('".self::SEQUENCE_NAME."')",
        );
    }
}
