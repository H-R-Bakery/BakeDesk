<?php

namespace App\Tests\Entity;

use App\Entity\Customer;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ReferenceEntityDefaultsTest extends TestCase
{
    public function testCustomerDefaults(): void
    {
        $customer = new Customer();

        self::assertTrue($customer->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $customer->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $customer->getUpdatedAt());
    }

    public function testUserDefaults(): void
    {
        $user = new User();

        self::assertTrue($user->isActive());
        self::assertSame(0, $user->getSortOrder());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getUpdatedAt());
    }

    public function testProductTypeDefaults(): void
    {
        $productType = new ProductType();

        self::assertTrue($productType->isActive());
        self::assertSame(0, $productType->getSortOrder());
    }

    public function testUnitDefaults(): void
    {
        $unit = new Unit();

        self::assertTrue($unit->isActive());
        self::assertSame(0, $unit->getSortOrder());
        self::assertNull($unit->getAbbreviation());
        self::assertFalse($unit->isPackageUnit());
    }
}
