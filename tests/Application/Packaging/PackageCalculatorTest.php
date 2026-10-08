<?php

declare(strict_types=1);

namespace App\Tests\Application\Packaging;

use App\Application\Packaging\FractionalPackageUnit;
use App\Application\Packaging\MissingPackagingRule;
use App\Application\Packaging\PackageCalculator;
use App\Entity\Customer;
use App\Entity\Employee;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PackagingRule;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
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
        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, Employee::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $this->calculator = self::getContainer()->get(PackageCalculator::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testPackageUnitQuantityCreatesOneAllocationPerWholeUnit(): void
    {
        $item = $this->item('Donuts', 'Dozen', '2', true);

        $allocations = $this->calculator->calculate($item);

        self::assertCount(2, $allocations);
        self::assertSame(['1', '1'], array_map(static fn ($allocation): string => $allocation->quantity, $allocations));
        self::assertSame([1, 2], array_map(static fn ($allocation): int => $allocation->packageNumber, $allocations));
        self::assertSame('Dozen', $allocations[0]->unit->getName());
    }

    public function testSinglePackageUnitCreatesOneAllocation(): void
    {
        $allocations = $this->calculator->calculate($this->item('Cookies', 'Tray', '1', true));

        self::assertCount(1, $allocations);
        self::assertSame('1', $allocations[0]->quantity);
        self::assertSame(1, $allocations[0]->packageCount);
    }

    public function testFractionalPackageUnitFailsWithoutRounding(): void
    {
        $this->expectException(FractionalPackageUnit::class);

        $this->calculator->calculate($this->item('Donuts', 'Dozen', '1.5', true));
    }

    public function testRuleBasedExactFitCreatesOneAllocation(): void
    {
        $item = $this->item('Cookies', 'Each', '24');
        $this->rule($item, '24');

        $allocations = $this->calculator->calculate($item);

        self::assertSame(['24'], array_map(static fn ($allocation): string => $allocation->quantity, $allocations));
    }

    public function testRuleBasedOverflowCreatesPartialFinalAllocation(): void
    {
        $item = $this->item('Cookies', 'Each', '30');
        $this->rule($item, '24');

        $allocations = $this->calculator->calculate($item);

        self::assertSame(['24', '6'], array_map(static fn ($allocation): string => $allocation->quantity, $allocations));
        self::assertSame([2, 2], array_map(static fn ($allocation): int => $allocation->packageCount, $allocations));
    }

    public function testRuleBasedMultipleOverflowUsesDecimalSafeArithmetic(): void
    {
        $item = $this->item('Cookies', 'Each', '50');
        $this->rule($item, '24');

        $allocations = $this->calculator->calculate($item);

        self::assertSame(['24', '24', '2'], array_map(static fn ($allocation): string => $allocation->quantity, $allocations));
    }

    public function testMissingRuleFailsWithProductAndUnitContext(): void
    {
        $item = $this->item('Brownies', 'Each', '1');

        try {
            $this->calculator->calculate($item);
            self::fail('A missing packaging rule should fail.');
        } catch (MissingPackagingRule $exception) {
            self::assertStringContainsString('Brownies / Each', $exception->getMessage());
        }
    }

    public function testInactiveRuleIsNotUsed(): void
    {
        $item = $this->item('Cookies', 'Each', '24');
        $this->rule($item, '24')->setActive(false);
        $this->entityManager->flush();

        $this->expectException(MissingPackagingRule::class);
        $this->calculator->calculate($item);
    }

    private function item(string $productName, string $unitName, string $quantity, bool $packageUnit = false): OrderItem
    {
        $productType = (new ProductType())->setName($productName);
        $unit = (new Unit())->setName($unitName)->setPackageUnit($packageUnit);
        $this->entityManager->persist($productType);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        return (new OrderItem())
            ->setProductType($productType)
            ->setUnit($unit)
            ->setQuantity($quantity)
            ->setDescription('Test item');
    }

    private function rule(OrderItem $item, string $quantity): PackagingRule
    {
        $rule = (new PackagingRule())
            ->setProductType($item->getProductType())
            ->setUnit($item->getUnit())
            ->setQuantityPerPackage($quantity);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }
}
