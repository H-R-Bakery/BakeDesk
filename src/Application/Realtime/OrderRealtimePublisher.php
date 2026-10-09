<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Entity\Order;
use App\Entity\PrintJob;
use App\Model\PrintDocumentType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class OrderRealtimePublisher
{
    public const ORDERS_TOPIC = 'bakedesk:orders';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publishOrderUpdated(Order $order): void
    {
        $orderId = $order->getId();
        if (null === $orderId) {
            return;
        }

        $this->publish(
            new Update(
                topics: [self::ORDERS_TOPIC, $this->orderTopic($orderId)],
                data: $this->encode([
                    'type' => 'order.updated',
                    'orderId' => $orderId,
                    'paid' => $order->isPaid(),
                    'status' => $order->getStatus()->value,
                ]),
                private: false,
            ),
            ['order_id' => $orderId, 'event_type' => 'order.updated'],
        );
    }

    public function publishPrintJobUpdated(PrintJob $printJob): void
    {
        $order = $printJob->getOrder();
        $orderId = $order?->getId();
        $printJobId = $printJob->getId();
        $documentType = $printJob->getDocumentType();
        if (null === $orderId || null === $printJobId || PrintDocumentType::LABEL !== $documentType) {
            return;
        }

        $this->publish(
            new Update(
                topics: $this->orderTopic($orderId),
                data: $this->encode([
                    'type' => 'print-job.updated',
                    'orderId' => $orderId,
                    'printJobId' => $printJobId,
                    'documentType' => $documentType->value,
                    'status' => $printJob->getStatus()->value,
                    'orderItemId' => $printJob->getOrderItem()?->getId(),
                    'packageNumber' => $printJob->getPackageNumber(),
                    'packageCount' => $printJob->getPackageCount(),
                    'errorMessage' => $printJob->getErrorMessage(),
                    'createdAt' => $printJob->getCreatedAt()->format(DATE_ATOM),
                ]),
                private: false,
            ),
            ['order_id' => $orderId, 'print_job_id' => $printJobId, 'event_type' => 'print-job.updated'],
        );
    }

    private function orderTopic(int $orderId): string
    {
        return 'bakedesk:order:'.$orderId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Mercure is an enhancement to the operational UI. A hub outage must not
     * change the result of an already-persisted application operation.
     *
     * @param array<string, int|string> $context
     */
    private function publish(Update $update, array $context): void
    {
        try {
            $this->hub->publish($update);
        } catch (\Throwable $exception) {
            $this->logger->warning('Mercure realtime publication failed.', [
                ...$context,
                'exception' => $exception,
            ]);
        }
    }
}
