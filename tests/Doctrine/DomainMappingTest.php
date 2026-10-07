<?php

namespace App\Tests\Doctrine;

use App\Entity\Customer;
use App\Entity\Employee;
use App\Entity\ProductType;
use App\Entity\Unit;
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
            [Customer::class, Employee::class, ProductType::class, Unit::class],
        );

        self::assertSame([], (new SchemaValidator($entityManager))->validateMapping());

        self::assertSame(['customer', 'employee', 'product_type', 'unit'], array_map(
            static fn ($entityMetadata): string => $entityMetadata->getTableName(),
            $metadata,
        ));
    }
}
