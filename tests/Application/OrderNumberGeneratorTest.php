<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Order\OrderNumberGenerator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OrderNumberGeneratorTest extends TestCase
{
    public function testSequenceValuesAreReturnedAsIncreasingNumericStrings(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(3))
            ->method('fetchOne')
            ->with("SELECT nextval('bakery_order_number_seq')")
            ->willReturnOnConsecutiveCalls('1000', '1001', '1002');

        $generator = new OrderNumberGenerator($connection);

        $numbers = [$generator->next(), $generator->next(), $generator->next()];

        self::assertSame(['1000', '1001', '1002'], $numbers);
        self::assertCount(3, array_unique($numbers));
        foreach ($numbers as $number) {
            self::assertMatchesRegularExpression('/^\d+$/', $number);
        }
        self::assertLessThan($numbers[1], $numbers[0]);
        self::assertLessThan($numbers[2], $numbers[1]);
    }
}
