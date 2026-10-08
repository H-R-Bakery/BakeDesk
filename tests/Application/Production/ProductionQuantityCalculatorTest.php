<?php

declare(strict_types=1);

namespace App\Tests\Application\Production;

use App\Application\Production\DecimalArithmetic;
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
        $this->calculator = new ProductionQuantityCalculator(new DecimalArithmetic());
    }

    public function testEachQuantityIsUnchangedByEachEquivalentOne(): void
    {
        self::assertSame('14', $this->calculator->calculate($this->item('14', '1')));
    }

    public function testConventionalDozenValueCanBeConfigured(): void
    {
        self::assertSame('24', $this->calculator->calculate($this->item('2', '12')));
    }

    public function testDozenDoesNotImplyTwelve(): void
    {
        self::assertSame('26', $this->calculator->calculate($this->item('2', '13')));
    }

    public function testFractionalQuantityUsesDecimalArithmetic(): void
    {
        self::assertSame('15', $this->calculator->calculate($this->item('1.5', '10')));
    }

    private function item(string $quantity, string $eachEquivalent): OrderItem
    {
        return (new OrderItem())
            ->setProductType((new ProductType())->setName('Donuts'))
            ->setUnit((new Unit())->setName('Dozen')->setEachEquivalent($eachEquivalent))
            ->setQuantity($quantity)
            ->setDescription('Plain');
    }
}
