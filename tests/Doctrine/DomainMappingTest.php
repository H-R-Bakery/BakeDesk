<?php

namespace App\Tests\Doctrine;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PackagingRule;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Entity\User;
use App\Model\OrderStatus;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
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
            [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );

        self::assertSame([], (new SchemaValidator($entityManager))->validateMapping());

        self::assertSame(['customer', 'users', 'product_type', 'unit', 'bakery_order', 'order_item', 'packaging_rule', 'printer', 'print_job'], array_map(
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

        $unitMetadata = $entityManager->getClassMetadata(Unit::class);
        self::assertTrue($unitMetadata->hasField('packageUnit'));
        self::assertSame('boolean', $unitMetadata->getFieldMapping('packageUnit')->type);
        self::assertSame('decimal', $unitMetadata->getFieldMapping('eachEquivalent')->type);
        self::assertSame(10, $unitMetadata->getFieldMapping('eachEquivalent')->precision);
        self::assertSame(2, $unitMetadata->getFieldMapping('eachEquivalent')->scale);

        $packagingRuleMetadata = $entityManager->getClassMetadata(PackagingRule::class);
        self::assertSame('decimal', $packagingRuleMetadata->getFieldMapping('quantityPerPackage')->type);
        self::assertSame(10, $packagingRuleMetadata->getFieldMapping('quantityPerPackage')->precision);
        self::assertSame(2, $packagingRuleMetadata->getFieldMapping('quantityPerPackage')->scale);
        self::assertSame(
            ['product_type_id', 'unit_id', 'active'],
            $packagingRuleMetadata->table['uniqueConstraints']['uniq_packaging_rule_product_unit_active']['columns'],
        );

        $printJobMetadata = $entityManager->getClassMetadata(PrintJob::class);
        self::assertSame(PrintDocumentType::class, $printJobMetadata->getFieldMapping('documentType')->enumType);
        self::assertSame(PrintJobStatus::class, $printJobMetadata->getFieldMapping('status')->enumType);
        self::assertTrue($printJobMetadata->hasField('documentPath'));
        self::assertTrue($printJobMetadata->getFieldMapping('documentPath')->nullable);
        self::assertTrue($printJobMetadata->hasAssociation('orderItem'));
        self::assertTrue($printJobMetadata->getFieldMapping('packageNumber')->nullable);
        self::assertTrue($printJobMetadata->getFieldMapping('packageCount')->nullable);
        self::assertTrue($printJobMetadata->getFieldMapping('packageQuantity')->nullable);
        self::assertSame('date_immutable', $printJobMetadata->getFieldMapping('reportDate')->type);
        self::assertTrue($printJobMetadata->getFieldMapping('reportDate')->nullable);

        $printerMetadata = $entityManager->getClassMetadata(Printer::class);
        self::assertTrue($printerMetadata->hasField('active'));
        self::assertTrue($printerMetadata->hasField('defaultForLabels'));
        self::assertSame(
            ['default_for_labels'],
            $printerMetadata->table['uniqueConstraints']['uniq_printer_default_for_labels']['columns'],
        );
        self::assertSame('datetime_immutable', $printerMetadata->getFieldMapping('createdAt')->type);
        self::assertSame('datetime_immutable', $printerMetadata->getFieldMapping('updatedAt')->type);
    }
}
