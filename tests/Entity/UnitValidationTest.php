<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Unit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UnitValidationTest extends KernelTestCase
{
    public function testNonPositiveEachEquivalentIsRejected(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);

        foreach (['0', '-1'] as $eachEquivalent) {
            $violations = $validator->validate((new Unit())->setEachEquivalent($eachEquivalent));

            self::assertNotCount(0, $violations, sprintf('Expected %s to be rejected.', $eachEquivalent));
            self::assertSame('eachEquivalent', $violations[0]->getPropertyPath());
        }
    }
}
