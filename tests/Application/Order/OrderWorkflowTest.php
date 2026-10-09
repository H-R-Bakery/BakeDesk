<?php

declare(strict_types=1);

namespace App\Tests\Application\Order;

use App\Application\Order\BakeryClock;
use App\Application\Order\OrderCompleter;
use App\Application\Order\OrderPaymentUpdater;
use App\Application\Order\OrderReopener;
use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\Order;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;

final class OrderWorkflowTest extends TestCase
{
    #[DataProvider('paymentTransitionProvider')]
    public function testPaymentUpdaterChangesEligibleOrdersAndPublishes(OrderStatus $status, bool $paid, bool $targetPaid): void
    {
        $order = (new Order())->setStatus($status)->setPaid($paid);
        $this->setId($order, 123);
        $oldUpdatedAt = $order->getUpdatedAt();
        $publisher = $this->publisher(1);
        $updater = new OrderPaymentUpdater($this->transactionalEntityManager(), $this->clock(), $publisher);

        $changed = $targetPaid ? $updater->markPaid($order) : $updater->markNotPaid($order);

        self::assertTrue($changed);
        self::assertSame($targetPaid, $order->isPaid());
        self::assertSame($status, $order->getStatus());
        self::assertNotSame($oldUpdatedAt, $order->getUpdatedAt());
    }

    /**
     * @return iterable<string, array{OrderStatus, bool, bool}>
     */
    public static function paymentTransitionProvider(): iterable
    {
        yield 'open to paid' => [OrderStatus::OPEN, false, true];
        yield 'completed to paid' => [OrderStatus::COMPLETED, false, true];
        yield 'open to not paid' => [OrderStatus::OPEN, true, false];
        yield 'completed to not paid' => [OrderStatus::COMPLETED, true, false];
    }

    #[DataProvider('alreadySetPaymentProvider')]
    public function testPaymentUpdaterTreatsAlreadySetPaymentAsANoOp(OrderStatus $status, bool $paid, bool $markPaid): void
    {
        $order = (new Order())->setStatus($status)->setPaid($paid);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $changed = $markPaid
            ? (new OrderPaymentUpdater($entityManager, $this->clock(), $this->publisher(0)))->markPaid($order)
            : (new OrderPaymentUpdater($entityManager, $this->clock(), $this->publisher(0)))->markNotPaid($order);

        self::assertFalse($changed);
        self::assertSame($paid, $order->isPaid());
    }

    /**
     * @return iterable<string, array{OrderStatus, bool, bool}>
     */
    public static function alreadySetPaymentProvider(): iterable
    {
        yield 'already paid and mark paid' => [OrderStatus::OPEN, true, true];
        yield 'already not paid and mark not paid' => [OrderStatus::COMPLETED, false, false];
    }

    #[DataProvider('invalidPaymentStatusProvider')]
    public function testPaymentUpdaterRejectsCancelledOrders(OrderStatus $status, bool $paid, bool $markPaid): void
    {
        $order = (new Order())->setStatus($status)->setPaid($paid);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $this->expectException(\LogicException::class);
        $updater = new OrderPaymentUpdater($entityManager, $this->clock(), $this->publisher(0));
        $markPaid ? $updater->markPaid($order) : $updater->markNotPaid($order);
    }

    /**
     * @return iterable<string, array{OrderStatus, bool, bool}>
     */
    public static function invalidPaymentStatusProvider(): iterable
    {
        yield 'cancelled unpaid mark paid' => [OrderStatus::CANCELLED, false, true];
        yield 'cancelled paid mark not paid' => [OrderStatus::CANCELLED, true, false];
    }

    public function testCompleterChangesAnOpenOrderAndPublishesAfterPersistence(): void
    {
        $order = (new Order())->setStatus(OrderStatus::OPEN)->setPaid(false);
        $this->setId($order, 123);
        $publisher = $this->publisher(1);

        (new OrderCompleter($this->transactionalEntityManager(), $this->clock(), $publisher))->complete($order);

        self::assertSame(OrderStatus::COMPLETED, $order->getStatus());
        self::assertFalse($order->isPaid());
    }

    public function testReopenerChangesACompletedOrderAndPublishesAfterPersistence(): void
    {
        $order = (new Order())->setStatus(OrderStatus::COMPLETED)->setPaid(false);
        $this->setId($order, 123);
        $publisher = $this->publisher(1);

        (new OrderReopener($this->transactionalEntityManager(), $this->clock(), $publisher))->reopen($order);

        self::assertSame(OrderStatus::OPEN, $order->getStatus());
        self::assertFalse($order->isPaid());
    }

    public function testCompletionStillSucceedsWhenMercurePublicationFails(): void
    {
        $order = (new Order())->setStatus(OrderStatus::OPEN);
        $this->setId($order, 123);
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->willThrowException(new \RuntimeException('Mercure unavailable'));

        (new OrderCompleter(
            $this->transactionalEntityManager(),
            $this->clock(),
            new OrderRealtimePublisher($hub, $this->createStub(LoggerInterface::class)),
        ))->complete($order);

        self::assertSame(OrderStatus::COMPLETED, $order->getStatus());
    }

    #[DataProvider('invalidCompletionStatuses')]
    public function testCompleterRejectsNonOpenOrders(OrderStatus $status): void
    {
        $order = (new Order())->setStatus($status);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $publisher = $this->publisher(0);

        $this->expectException(\LogicException::class);
        (new OrderCompleter($entityManager, $this->clock(), $publisher))->complete($order);
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function invalidCompletionStatuses(): iterable
    {
        yield 'completed' => [OrderStatus::COMPLETED];
        yield 'cancelled' => [OrderStatus::CANCELLED];
    }

    #[DataProvider('invalidReopenStatuses')]
    public function testReopenerRejectsNonCompletedOrders(OrderStatus $status): void
    {
        $order = (new Order())->setStatus($status);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $publisher = $this->publisher(0);

        $this->expectException(\LogicException::class);
        (new OrderReopener($entityManager, $this->clock(), $publisher))->reopen($order);
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function invalidReopenStatuses(): iterable
    {
        yield 'open' => [OrderStatus::OPEN];
        yield 'cancelled' => [OrderStatus::CANCELLED];
    }

    private function transactionalEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function clock(): BakeryClock
    {
        return new BakeryClock('America/Indiana/Indianapolis');
    }

    private function publisher(int $expectedPublications): OrderRealtimePublisher
    {
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects(self::exactly($expectedPublications))
            ->method('publish')
            ->willReturn('update-id');

        return new OrderRealtimePublisher($hub, $this->createStub(LoggerInterface::class));
    }

    private function setId(Order $order, int $id): void
    {
        $property = new \ReflectionProperty($order, 'id');
        $property->setValue($order, $id);
    }
}
