<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\OrderItem;
use App\Entity\PackagingRule;
use App\Entity\Unit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UnitValidationTest extends KernelTestCase
{
    public function testQuantityValuesArePositiveIntegers(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $unit = new Unit();
        $item = (new OrderItem())->setQuantity(25);
        $rule = (new PackagingRule())->setQuantityPerPackage(12);

        self::assertSame([], iterator_to_array($validator->validateProperty($item, 'quantity')));
        self::assertSame([], iterator_to_array($validator->validateProperty($unit->setEachEquivalent(6), 'eachEquivalent')));
        self::assertSame([], iterator_to_array($validator->validateProperty($rule, 'quantityPerPackage')));
        self::assertSame(25, $item->getQuantity());
        self::assertSame(6, $unit->getEachEquivalent());
        self::assertSame(12, $rule->getQuantityPerPackage());
    }

    public function testNonPositiveEachEquivalentIsRejected(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);

        foreach ([0, -1] as $eachEquivalent) {
            $violations = $validator->validate((new Unit())->setEachEquivalent($eachEquivalent));

            self::assertNotCount(0, $violations, sprintf('Expected %s to be rejected.', $eachEquivalent));
            self::assertSame('eachEquivalent', $violations[0]->getPropertyPath());
        }
    }
}
