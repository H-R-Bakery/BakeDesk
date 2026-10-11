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
        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $this->calculator = self::getContainer()->get(OrderPackageCalculator::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testMixedItemsKeepItemIdentityAndReceiveOrderWideNumbering(): void
    {
        $product = $this->product('Donuts');
        $dozen = $this->unit('Dozen');
        $halfDozen = $this->unit('Half Dozen');
        $order = $this->order();
        $order->addItem((new OrderItem())
            ->setProductType($product)
            ->setUnit($dozen)
            ->setQuantity(2)
            ->setDescription('Glazed')
            ->setSortOrder(10));
        $order->addItem((new OrderItem())
            ->setProductType($product)
            ->setUnit($halfDozen)
            ->setQuantity(1)
            ->setDescription('Iced')
            ->setSortOrder(20));
        $this->rule($product, $dozen, 1);
        $this->entityManager->flush();

        $allocations = $this->calculator->calculate($order);

        self::assertSame([[1, 2, 1, 3], [2, 2, 2, 3], [1, 1, 3, 3]], array_map(
            static fn ($allocation): array => [$allocation->itemPackageNumber, $allocation->itemPackageCount, $allocation->orderPackageNumber, $allocation->orderPackageCount],
            $allocations,
        ));
    }

    public function testSingleItemUsesTheSameItemAndOrderPositions(): void
    {
        $product = $this->product('Donuts');
        $unit = $this->unit('Dozen', true);
        $order = $this->order();
        $order->addItem((new OrderItem())
            ->setProductType($product)
            ->setUnit($unit)
            ->setQuantity(3)
            ->setDescription('Glazed'));
        $this->entityManager->flush();

        $allocations = $this->calculator->calculate($order);

        self::assertSame([[1, 3, 1, 3], [2, 3, 2, 3], [3, 3, 3, 3]], array_map(
            static fn ($allocation): array => [$allocation->itemPackageNumber, $allocation->itemPackageCount, $allocation->orderPackageNumber, $allocation->orderPackageCount],
            $allocations,
        ));
    }

    public function testMultipleSinglePackageItemsReceiveDifferentOrderPositions(): void
    {
        $order = $this->order();
        foreach (['Donuts', 'Brownies', 'Cookies'] as $sortOrder => $name) {
            $order->addItem((new OrderItem())
                ->setProductType($this->product($name))
                ->setUnit($this->unit('Each'))
                ->setQuantity(1)
                ->setDescription($name)
                ->setSortOrder($sortOrder));
        }
        $this->entityManager->flush();

        $allocations = $this->calculator->calculate($order);

        self::assertSame([[1, 1, 1, 3], [1, 1, 2, 3], [1, 1, 3, 3]], array_map(
            static fn ($allocation): array => [$allocation->itemPackageNumber, $allocation->itemPackageCount, $allocation->orderPackageNumber, $allocation->orderPackageCount],
            $allocations,
        ));
    }

    private function order(): Order
    {
        $customer = (new Customer())
            ->setName('Test Customer')
            ->setPhone(PhoneNumberUtil::getInstance()->parse('+18125551234', 'US'));
        $user = User::new(email: uniqid('order-taker-', true).'@example.test', name: 'Order Taker', employee: true);
        $this->entityManager->persist($customer);
        $this->entityManager->persist($user);

        return (new Order())
            ->setOrderNumber(uniqid('order-', true))
            ->setCustomer($customer)
            ->setUser($user)
            ->setPickupAt(new \DateTimeImmutable('2026-10-10 09:00:00', new \DateTimeZone('UTC')))
            ->setOrderedAt(new \DateTimeImmutable('2026-10-09 09:00:00', new \DateTimeZone('UTC')));
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

    private function rule(ProductType $product, Unit $unit, int $capacity): void
    {
        $this->entityManager->persist((new PackagingRule())
            ->setProductType($product)
            ->setUnit($unit)
            ->setQuantityPerPackage($capacity));
    }
}
