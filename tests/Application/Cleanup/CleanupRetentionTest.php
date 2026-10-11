<?php

declare(strict_types=1);

namespace App\Tests\Application\Cleanup;

use App\Application\Cleanup\CleanupRetention;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CleanupRetentionTest extends TestCase
{
    #[DataProvider('invalidRetentionValues')]
    public function testInvalidRetentionValuesAreRejected(string $printJobDays, string $orderDays): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CleanupRetention::fromStrings($printJobDays, $orderDays);
    }

    public static function invalidRetentionValues(): \Generator
    {
        yield ['0', '730'];
        yield ['-1', '730'];
        yield ['90', '0'];
        yield ['90', '-1'];
        yield ['ninety', '730'];
        yield ['90', '730.5'];
        yield ['', '730'];
    }

    public function testPositiveRetentionValuesProduceUtcCutoffs(): void
    {
        $retention = CleanupRetention::fromStrings('90', '730');
        $now = new \DateTimeImmutable('2026-10-11 15:30:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-07-13T15:30:00+00:00', $retention->printJobCutoff($now)->format(DATE_ATOM));
        self::assertSame('2024-10-11T15:30:00+00:00', $retention->orderCutoff($now)->format(DATE_ATOM));
    }
}
