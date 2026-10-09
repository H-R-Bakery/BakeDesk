<?php

declare(strict_types=1);

namespace App\Tests\Application\Order;

use App\Application\Order\BakeryClock;
use App\Application\Order\OrderCompleter;
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
