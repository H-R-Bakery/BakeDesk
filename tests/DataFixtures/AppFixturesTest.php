<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\Entity\PackagingRule;
use App\Entity\ProductType;
use App\Entity\Unit;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class AppFixturesTest extends TestCase
{
    public function testReferenceDataIsPersistedWithExpectedValues(): void
    {
        $persisted = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager
            ->expects(self::exactly(18))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $manager->expects(self::once())->method('flush');

        (new AppFixtures())->load($manager);

        $productTypes = array_values(array_filter(
            $persisted,
            static fn (object $entity): bool => $entity instanceof ProductType,
        ));
        $units = array_values(array_filter(
            $persisted,
            static fn (object $entity): bool => $entity instanceof Unit,
        ));

        self::assertSame(
            [
                ['Donuts', 10, true],
                ['Brownies', 20, true],
                ['Cookies', 30, true],
                ['Shape Cookies', 40, true],
            ],
            array_map(
                static fn (ProductType $productType): array => [
                    $productType->getName(),
                    $productType->getSortOrder(),
                    $productType->isActive(),
                ],
                $productTypes,
            ),
        );

        self::assertSame(
            [
                ['Each', 'ea', 10, true, false],
                ['Dozen', 'doz', 20, true, true],
                ['Half Dozen', '1/2 doz', 30, true, false],
                ['Tray', 'tray', 40, true, true],
                ['Box', 'box', 50, true, true],
            ],
            array_map(
                static fn (Unit $unit): array => [
                    $unit->getName(),
                    $unit->getAbbreviation(),
                    $unit->getSortOrder(),
                    $unit->isActive(),
                    $unit->isPackageUnit(),
                ],
                $units,
            ),
        );

        $rules = array_values(array_filter(
            $persisted,
            static fn (object $entity): bool => $entity instanceof PackagingRule,
        ));
        self::assertCount(1, $rules);
        self::assertSame('24', $rules[0]->getQuantityPerPackage());
        self::assertSame('Cookies', $rules[0]->getProductType()?->getName());
        self::assertSame('Each', $rules[0]->getUnit()?->getName());
    }
}
