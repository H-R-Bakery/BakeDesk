<?php

declare(strict_types=1);

namespace App\Tests\Application\Printing;

use App\Application\Printing\IppJobStateMapper;
use App\Application\Printing\PrintJobState;
use App\Application\Printing\PrintJobStatusSnapshot;
use App\Model\PrintJobStatus;
use PHPUnit\Framework\TestCase;

final class IppJobStateMapperTest extends TestCase
{
    public function testPendingAndProcessingRemainSubmitted(): void
    {
        $mapper = new IppJobStateMapper();

        self::assertSame(PrintJobStatus::SUBMITTED, $mapper->toPrintJobStatus(new PrintJobStatusSnapshot(PrintJobState::PENDING)));
        self::assertSame(PrintJobStatus::SUBMITTED, $mapper->toPrintJobStatus(new PrintJobStatusSnapshot(PrintJobState::PROCESSING)));
        self::assertSame(PrintJobStatus::SUBMITTED, $mapper->toPrintJobStatus(new PrintJobStatusSnapshot(PrintJobState::UNKNOWN)));
    }

    public function testTerminalStatesMapToBakeDeskStates(): void
    {
        $mapper = new IppJobStateMapper();

        self::assertSame(PrintJobStatus::COMPLETED, $mapper->toPrintJobStatus(new PrintJobStatusSnapshot(PrintJobState::COMPLETED)));
        self::assertSame(PrintJobStatus::CANCELLED, $mapper->toPrintJobStatus(new PrintJobStatusSnapshot(PrintJobState::CANCELLED)));
        self::assertSame(PrintJobStatus::FAILED, $mapper->toPrintJobStatus(new PrintJobStatusSnapshot(PrintJobState::ABORTED)));
    }
}
