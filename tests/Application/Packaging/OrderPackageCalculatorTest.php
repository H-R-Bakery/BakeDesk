<?php

declare(strict_types=1);

namespace App\Tests\Application\Packaging;

use App\Application\Packaging\OrderPackageCalculator;
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
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderPackageCalculatorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderPackageCalculator $calculator;

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = array_map($this->entityManager->getClassMetadata(...), [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class]);
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $this->calculator = self::getContainer()->get(OrderPackageCalculator::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testCompatibleLinesCombineAndFillCapacityAcrossLines(): void
    {
        $order = $this->order();
        $product = $this->product('Donuts');
        $unit = $this->unit('Each');
        $this->add($order, $product, $unit, 8, 'Glazed', 10);
        $this->add($order, $product, $unit, 6, 'Iced', 20);
        $this->rule($product, $unit, 12);
        $this->entityManager->flush();

        $allocations = $this->calculator->calculate($order);

        self::assertSame([[['Glazed', 8], ['Iced', 4]], [['Iced', 2]]], array_map(
            static fn ($allocation): array => array_map(static fn ($line): array => [$line->orderItem->getDescription(), $line->quantity], $allocation->lines),
            $allocations,
        ));
        self::assertSame([1, 2], array_map(static fn ($allocation): int => $allocation->packageNumber, $allocations));
        self::assertSame([2, 2], array_map(static fn ($allocation): int => $allocation->packageCount, $allocations));
    }

    public function testSeveralLinesFillThreePackagesSequentially(): void
    {
        $order = $this->order();
        $product = $this->product('Donuts');
        $unit = $this->unit('Each');
        foreach ([['Glazed', 10], ['Iced', 20], ['Powdered', 30]] as $entry) {
            $this->add($order, $product, $unit, 10, $entry[0], $entry[1]);
        }
        $this->rule($product, $unit, 12);
        $this->entityManager->flush();

        self::assertSame([[['Glazed', 10], ['Iced', 2]], [['Iced', 8], ['Powdered', 4]], [['Powdered', 6]]], array_map(
            static fn ($allocation): array => array_map(static fn ($line): array => [$line->orderItem->getDescription(), $line->quantity], $allocation->lines),
            $this->calculator->calculate($order),
        ));
    }

    public function testProductTypesAndUnitsDoNotCombine(): void
    {
        $order = $this->order();
        $donuts = $this->product('Donuts');
        $cookies = $this->product('Cookies');
        $each = $this->unit('Each');
        $dozen = $this->unit('Dozen');
        $this->add($order, $donuts, $each, 1, 'Donut', 10);
        $this->add($order, $cookies, $each, 3, 'Cookie', 20);
        $this->add($order, $donuts, $dozen, 1, 'Dozen', 30);
        $this->rule($donuts, $each, 12);
        $this->rule($cookies, $each, 24);
        $this->rule($donuts, $dozen, 12);
        $this->entityManager->flush();

        self::assertCount(3, $this->calculator->calculate($order));
    }

    public function testMissingRuleCombinesCompatibleLinesInOnePackage(): void
    {
        $order = $this->order();
        $product = $this->product('Brownies');
        $unit = $this->unit('Each');
        $this->add($order, $product, $unit, 1, 'Chocolate', 10);
        $this->add($order, $product, $unit, 2, 'Walnut', 20);
        $this->entityManager->flush();

        $allocations = $this->calculator->calculate($order);
        self::assertCount(1, $allocations);
        self::assertSame([1, 2], array_map(static fn ($line): int => $line->quantity, $allocations[0]->lines));
    }

    public function testPackageUnitQuantityCreatesOnePhysicalPackagePerUnit(): void
    {
        $order = $this->order();
        $this->add($order, $this->product('Donuts'), $this->unit('Box', true), 2, 'Assorted', 10);
        $this->entityManager->flush();

        $allocations = $this->calculator->calculate($order);
        self::assertCount(2, $allocations);
        self::assertSame([1, 2], array_map(static fn ($allocation): int => $allocation->packageNumber, $allocations));
        self::assertSame([1, 1], array_map(static fn ($allocation): int => $allocation->lines[0]->quantity, $allocations));
    }

    public function testSeparatePackageUnitOrderItemsRemainSeparatePhysicalPackages(): void
    {
        $order = $this->order();
        $product = $this->product('Donuts');
        $unit = $this->unit('Box', true);
        $this->add($order, $product, $unit, 1, 'Glazed', 10);
        $this->add($order, $product, $unit, 1, 'Iced', 20);
        $this->entityManager->flush();

        $allocations = $this->calculator->calculate($order);

        self::assertCount(2, $allocations);
        self::assertSame(['Glazed', 'Iced'], array_map(static fn ($allocation): string => $allocation->lines[0]->orderItem->getDescription(), $allocations));
        self::assertSame([1, 2], array_map(static fn ($allocation): int => $allocation->packageNumber, $allocations));
    }

    public function testSnapshotRoundTripKeepsEveryPackageLine(): void
    {
        $order = $this->order();
        $product = $this->product('Donuts');
        $unit = $this->unit('Each');
        $this->add($order, $product, $unit, 1, 'Glazed', 10);
        $this->add($order, $product, $unit, 1, 'Iced', 20);
        $this->rule($product, $unit, 12);
        $this->entityManager->flush();
        $allocation = $this->calculator->calculate($order)[0];

        $restored = \App\Application\Packaging\PackageAllocation::fromSnapshot($allocation->toSnapshot(), $order);
        self::assertSame([1, 1], array_map(static fn ($line): int => $line->quantity, $restored->lines));
        self::assertSame('Glazed', $restored->lines[0]->orderItem->getDescription());
        self::assertSame('Iced', $restored->lines[1]->orderItem->getDescription());
    }

    private function order(): Order
    {
        $customer = (new Customer())->setName('Test Customer')->setPhone(PhoneNumberUtil::getInstance()->parse('+18125551234', 'US'));
        $user = User::new(email: uniqid('order-taker-', true).'@example.test', name: 'Order Taker', employee: true);
        $this->entityManager->persist($customer);
        $this->entityManager->persist($user);

        return (new Order())->setOrderNumber(uniqid('order-', true))->setCustomer($customer)->setUser($user)->setPickupAt(new \DateTimeImmutable('2026-10-10 09:00:00', new \DateTimeZone('UTC')))->setOrderedAt(new \DateTimeImmutable('2026-10-09 09:00:00', new \DateTimeZone('UTC')));
    }

    private function product(string $name): ProductType
    {
        $product = (new ProductType())->setName($name);
        $this->entityManager->persist($product);

        return $product;
    }

    private function unit(string $name, bool $packageUnit = false): Unit
    {
        $unit = (new Unit())->setName($name)->setPackageUnit($packageUnit);
        $this->entityManager->persist($unit);

        return $unit;
    }

    private function add(Order $order, ProductType $product, Unit $unit, int $quantity, string $description, int $sortOrder): void
    {
        $order->addItem((new OrderItem())->setProductType($product)->setUnit($unit)->setQuantity($quantity)->setDescription($description)->setSortOrder($sortOrder));
    }

    private function rule(ProductType $product, Unit $unit, int $capacity): void
    {
        $this->entityManager->persist((new PackagingRule())->setProductType($product)->setUnit($unit)->setQuantityPerPackage($capacity));
    }
}
