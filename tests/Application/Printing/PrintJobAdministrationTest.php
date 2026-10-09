<?php

declare(strict_types=1);

namespace App\Tests\Application\Printing;

use App\Application\Printing\PrintJobCanceller;
use App\Application\Printing\PrintJobDeleter;
use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\Order;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

final class PrintJobAdministrationTest extends TestCase
{
    #[DataProvider('cancellableStatuses')]
    public function testCancellableStatusesBecomeCancelledAndKeepHistory(PrintJobStatus $status): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $transaction): null {
                $transaction();

                return null;
            });
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish');
        $publisher = new OrderRealtimePublisher($hub, new NullLogger());

        $printer = (new Printer())->setName('History printer')->setAddress('ipp://printer.example/print');
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(123);
        $printJob = (new PrintJob())
            ->setPrinter($printer)
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus($status)
            ->setOrder($order)
            ->setPackageNumber(1)
            ->setPackageCount(2)
            ->setPackageQuantity('6')
            ->setDocumentPath('labels/2026/10/order-label.pdf')
            ->setAttemptCount(3);
        $id = new \ReflectionProperty(PrintJob::class, 'id');
        $id->setValue($printJob, 1);

        (new PrintJobCanceller($entityManager, $publisher))->cancel($printJob);

        self::assertSame(PrintJobStatus::CANCELLED, $printJob->getStatus());
        self::assertSame('Cancelled by an administrator.', $printJob->getErrorMessage());
        self::assertSame($printer, $printJob->getPrinter());
        self::assertSame('labels/2026/10/order-label.pdf', $printJob->getDocumentPath());
        self::assertSame(3, $printJob->getAttemptCount());
        self::assertSame(1, $printJob->getPackageNumber());
        self::assertSame(2, $printJob->getPackageCount());
        self::assertSame('6', $printJob->getPackageQuantity());
    }

    #[DataProvider('nonCancellableStatuses')]
    public function testNonCancellableStatusesAreRejected(PrintJobStatus $status): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $printJob = (new PrintJob())->setStatus($status);

        $this->expectException(\LogicException::class);
        (new PrintJobCanceller($entityManager))->cancel($printJob);
    }

    #[DataProvider('deletableStatuses')]
    public function testTerminalPrintJobsCanBeDeletedWithoutStorageAccess(PrintJobStatus $status): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove');
        $entityManager->expects(self::once())->method('flush');
        $printJob = (new PrintJob())
            ->setStatus($status)
            ->setDocumentPath('shared/document.pdf');

        (new PrintJobDeleter($entityManager))->delete($printJob);

        self::assertSame('shared/document.pdf', $printJob->getDocumentPath());
    }

    #[DataProvider('nonDeletableStatuses')]
    public function testNonTerminalPrintJobsCannotBeDeleted(PrintJobStatus $status): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('remove');
        $entityManager->expects(self::never())->method('flush');

        $this->expectException(\LogicException::class);
        (new PrintJobDeleter($entityManager))->delete((new PrintJob())->setStatus($status));
    }

    /** @return iterable<string, array{PrintJobStatus}> */
    public static function cancellableStatuses(): iterable
    {
        foreach ([PrintJobStatus::QUEUED, PrintJobStatus::PROCESSING, PrintJobStatus::RENDERED] as $status) {
            yield $status->value => [$status];
        }
    }

    /** @return iterable<string, array{PrintJobStatus}> */
    public static function nonCancellableStatuses(): iterable
    {
        foreach ([PrintJobStatus::SUBMITTED, PrintJobStatus::COMPLETED, PrintJobStatus::FAILED, PrintJobStatus::CANCELLED] as $status) {
            yield $status->value => [$status];
        }
    }

    /** @return iterable<string, array{PrintJobStatus}> */
    public static function deletableStatuses(): iterable
    {
        foreach ([PrintJobStatus::COMPLETED, PrintJobStatus::FAILED, PrintJobStatus::CANCELLED] as $status) {
            yield $status->value => [$status];
        }
    }

    /** @return iterable<string, array{PrintJobStatus}> */
    public static function nonDeletableStatuses(): iterable
    {
        foreach ([PrintJobStatus::QUEUED, PrintJobStatus::PROCESSING, PrintJobStatus::RENDERED, PrintJobStatus::SUBMITTED] as $status) {
            yield $status->value => [$status];
        }
    }
}
