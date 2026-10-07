<?php

namespace App\Tests\Doctrine;

use App\Entity\Customer;
use App\Entity\Employee;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DomainMappingTest extends KernelTestCase
{
    public function testDomainMappingsAreValidAndProduceExpectedSchema(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = array_map(
            $entityManager->getClassMetadata(...),
            [Customer::class, Employee::class, ProductType::class, Unit::class, Order::class, OrderItem::class],
        );

        self::assertSame([], (new SchemaValidator($entityManager))->validateMapping());

        self::assertSame(['customer', 'employee', 'product_type', 'unit', 'bakery_order', 'order_item'], array_map(
            static fn ($entityMetadata): string => $entityMetadata->getTableName(),
            $metadata,
        ));

        $orderMetadata = $entityManager->getClassMetadata(Order::class);
        self::assertSame(OrderStatus::class, $orderMetadata->getFieldMapping('status')->enumType);
        self::assertSame(
            ['order_number'],
            $orderMetadata->table['uniqueConstraints']['uniq_bakery_order_order_number']['columns'],
        );

        $orderItemMetadata = $entityManager->getClassMetadata(OrderItem::class);
        $quantityMapping = $orderItemMetadata->getFieldMapping('quantity');
        self::assertSame('decimal', $quantityMapping->type);
        self::assertSame(10, $quantityMapping->precision);
        self::assertSame(2, $quantityMapping->scale);
    }
}
