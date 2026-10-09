<?php

declare(strict_types=1);

namespace App\Tests\Application\Realtime;

use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PrintJob;
use App\Model\OrderStatus;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class OrderRealtimePublisherTest extends TestCase
{
    public function testOrderUpdatePublishesSmallPayloadToGlobalAndOrderTopics(): void
    {
        $order = (new Order())
            ->setPaid(true)
            ->setStatus(OrderStatus::COMPLETED);
        $this->setId($order, 123);
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects(self::once())
            ->method('publish')
            ->with(self::callback(function (Update $update): bool {
                self::assertSame(['bakedesk:orders', 'bakedesk:order:123'], $update->getTopics());
                self::assertSame([
                    'type' => 'order.updated',
                    'orderId' => 123,
                    'paid' => true,
                    'status' => 'completed',
                ], json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR));

                return true;
            }))
            ->willReturn('update-id');

        (new OrderRealtimePublisher($hub, $this->createStub(LoggerInterface::class)))->publishOrderUpdated($order);
    }

    #[DataProvider('printStatusProvider')]
    public function testLabelStatusPublishesPackageSnapshot(PrintJobStatus $status): void
    {
        $order = new Order();
        $this->setId($order, 123);
        $item = (new OrderItem())->setOrder($order);
        $this->setId($item, 87);
        $printJob = (new PrintJob())
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus($status)
            ->setOrder($order)
            ->setOrderItem($item)
            ->setPackageNumber(2)
            ->setPackageCount(3)
            ->setErrorMessage(PrintJobStatus::FAILED === $status ? 'Printer unavailable' : null);
        $this->setId($printJob, 456);
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects(self::once())
            ->method('publish')
            ->with(self::callback(function (Update $update) use ($status): bool {
                self::assertSame(['bakedesk:order:123'], $update->getTopics());
                $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('print-job.updated', $payload['type']);
                self::assertSame('label', $payload['documentType']);
                self::assertSame($status->value, $payload['status']);
                self::assertSame(456, $payload['printJobId']);
                self::assertSame(87, $payload['orderItemId']);
                self::assertSame(2, $payload['packageNumber']);
                self::assertSame(3, $payload['packageCount']);

                return true;
            }))
            ->willReturn('update-id');

        (new OrderRealtimePublisher($hub, $this->createStub(LoggerInterface::class)))->publishPrintJobUpdated($printJob);
    }

    /**
     * @return iterable<string, array{PrintJobStatus}>
     */
    public static function printStatusProvider(): iterable
    {
        foreach (PrintJobStatus::cases() as $status) {
            yield $status->name => [$status];
        }
    }

    public function testMercureFailureIsLoggedAndDoesNotEscape(): void
    {
        $order = new Order();
        $this->setId($order, 123);
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects(self::once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('Mercure unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with('Mercure realtime publication failed.', self::arrayHasKey('exception'));

        (new OrderRealtimePublisher($hub, $logger))->publishOrderUpdated($order);
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
