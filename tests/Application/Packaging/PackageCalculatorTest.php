<?php

declare(strict_types=1);

namespace App\Tests\Application\Packaging;

use App\Application\Packaging\PackageCalculator;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PackagingRule;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageCalculatorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PackageCalculator $calculator;

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = array_map($this->entityManager->getClassMetadata(...), [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class]);
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $this->calculator = self::getContainer()->get(PackageCalculator::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testConfiguredCapacityIsReturnedAndMissingRulesHaveNoCapacity(): void
    {
        $item = $this->item('Cookies', 'Each', 24);
        $rule = (new PackagingRule())->setProductType($item->getProductType())->setUnit($item->getUnit())->setQuantityPerPackage(24);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        self::assertSame(24, $this->calculator->capacityFor($item));
        self::assertNull($this->calculator->capacityFor($this->item('Brownies', 'Each', 100)));
        self::assertNull($this->calculator->capacityFor($this->item('Donuts', 'Box', 2, true)));
    }

    public function testInvalidCapacityIsRejected(): void
    {
        $item = $this->item('Cookies', 'Each', 1);
        $this->entityManager->persist((new PackagingRule())->setProductType($item->getProductType())->setUnit($item->getUnit())->setQuantityPerPackage(0));
        $this->entityManager->flush();

        $this->expectException(\App\Application\Packaging\InvalidPackagingQuantity::class);
        $this->calculator->capacityFor($item);
    }

    private function item(string $productName, string $unitName, int $quantity, bool $packageUnit = false): OrderItem
    {
        $product = (new ProductType())->setName($productName);
        $unit = (new Unit())->setName($unitName)->setPackageUnit($packageUnit);
        $this->entityManager->persist($product);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        return (new OrderItem())->setProductType($product)->setUnit($unit)->setQuantity($quantity)->setDescription('Test item');
    }
}
