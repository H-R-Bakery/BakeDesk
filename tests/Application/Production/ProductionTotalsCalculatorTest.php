<?php

declare(strict_types=1);

namespace App\Tests\Application\Production;

use App\Application\Production\DecimalArithmetic;
use App\Application\Production\ProductionQuantityCalculator;
use App\Application\Production\ProductionTotalsCalculator;
use App\Entity\OrderItem;
use App\Entity\ProductType;
use App\Entity\Unit;
use PHPUnit\Framework\TestCase;

final class ProductionTotalsCalculatorTest extends TestCase
{
    public function testTotalsConvertAndAggregateByProductType(): void
    {
        $calculator = $this->calculator();
        $donuts = (new ProductType())->setName('Donuts');
        $cookies = (new ProductType())->setName('Cookies');
        $dozen = (new Unit())->setName('Dozen')->setEachEquivalent('12');
        $each = (new Unit())->setName('Each')->setEachEquivalent('1');
        $tray = (new Unit())->setName('Tray')->setEachEquivalent('20');

        $totals = $calculator->calculate([
            $this->item($donuts, '1', $dozen),
            $this->item($donuts, '14', $each),
            $this->item($cookies, '2', $each),
            $this->item($cookies, '1', $tray),
        ]);

        self::assertSame(['Donuts' => '26', 'Cookies' => '22'], $totals);
    }

    public function testChangingDozenConfigurationChangesTotals(): void
    {
        $calculator = $this->calculator();
        $donuts = (new ProductType())->setName('Donuts');
        $dozen = (new Unit())->setName('Dozen')->setEachEquivalent('13');
        $each = (new Unit())->setName('Each')->setEachEquivalent('1');

        self::assertSame(['Donuts' => '27'], $calculator->calculate([
            $this->item($donuts, '1', $dozen),
            $this->item($donuts, '14', $each),
        ]));
    }

    private function calculator(): ProductionTotalsCalculator
    {
        $arithmetic = new DecimalArithmetic();

        return new ProductionTotalsCalculator(new ProductionQuantityCalculator($arithmetic), $arithmetic);
    }

    private function item(ProductType $productType, string $quantity, Unit $unit): OrderItem
    {
        return (new OrderItem())
            ->setProductType($productType)
            ->setUnit($unit)
            ->setQuantity($quantity)
            ->setDescription('Plain');
    }
}
