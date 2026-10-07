<?php

namespace App\Tests\Model;

use App\Model\OrderStatus;
use App\Model\PrintJobStatus;
use PHPUnit\Framework\TestCase;

final class WorkflowStatusTest extends TestCase
{
    public function testOrderStatusValues(): void
    {
        self::assertSame(['open', 'completed', 'cancelled'], array_map(
            static fn (OrderStatus $status): string => $status->value,
            OrderStatus::cases(),
        ));
    }

    public function testPrintJobStatusValues(): void
    {
        self::assertSame(['queued', 'processing', 'submitted', 'completed', 'failed', 'cancelled'], array_map(
            static fn (PrintJobStatus $status): string => $status->value,
            PrintJobStatus::cases(),
        ));
    }
}
