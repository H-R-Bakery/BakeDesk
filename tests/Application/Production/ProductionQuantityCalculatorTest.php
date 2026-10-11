<?php

declare(strict_types=1);

namespace App\Tests\Application\Production;

use App\Application\Production\ProductionQuantityCalculator;
use App\Entity\OrderItem;
use App\Entity\ProductType;
use App\Entity\Unit;
use PHPUnit\Framework\TestCase;

final class ProductionQuantityCalculatorTest extends TestCase
{
    private ProductionQuantityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProductionQuantityCalculator();
    }

    public function testEachQuantityIsUnchangedByEachEquivalentOne(): void
    {
        self::assertSame(14, $this->calculator->calculate($this->item(14, 1)));
    }

    public function testConventionalDozenValueCanBeConfigured(): void
    {
        self::assertSame(24, $this->calculator->calculate($this->item(2, 12)));
    }

    public function testDozenDoesNotImplyTwelve(): void
    {
        self::assertSame(26, $this->calculator->calculate($this->item(2, 13)));
    }

    public function testHalfDozenConversionUsesIntegerArithmetic(): void
    {
        self::assertSame(18, $this->calculator->calculate($this->item(3, 6)));
    }

    private function item(int $quantity, int $eachEquivalent): OrderItem
    {
        return (new OrderItem())
            ->setProductType((new ProductType())->setName('Donuts'))
            ->setUnit((new Unit())->setName('Dozen')->setEachEquivalent($eachEquivalent))
            ->setQuantity($quantity)
            ->setDescription('Plain');
    }
}
