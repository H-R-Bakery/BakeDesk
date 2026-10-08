<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Order\BakeryClock;
use App\Entity\OrderItem;
use App\Repository\OrderRepository;

final readonly class ProductionReportBuilder
{
    public function __construct(
        private OrderRepository $orderRepository,
        private ProductionQuantityCalculator $productionQuantityCalculator,
        private ProductionTotalsCalculator $productionTotalsCalculator,
        private BakeryClock $bakeryClock,
    ) {
    }

    public function build(\DateTimeImmutable $pickupDate): ProductionReport
    {
        $timezone = new \DateTimeZone($this->bakeryClock->getTimezoneName());
        $localStart = new \DateTimeImmutable($pickupDate->format('Y-m-d'), $timezone);
        $localEnd = $localStart->modify('+1 day');
        $orders = $this->orderRepository->findForProductionReport(
            $localStart->setTimezone(new \DateTimeZone('UTC')),
            $localEnd->setTimezone(new \DateTimeZone('UTC')),
        );

        /** @var array<string, array{name: string, items: list<OrderItem>}> $groupedItems */
        $groupedItems = [];
        foreach ($orders as $order) {
            foreach ($order->getItems() as $orderItem) {
                $productType = $orderItem->getProductType();
                if (null === $productType) {
                    throw new \InvalidArgumentException('An order item must have a product type before a production report can be built.');
                }

                $key = null !== $productType->getId()
                    ? (string) $productType->getId()
                    : spl_object_hash($productType);
                $groupedItems[$key] ??= ['name' => $productType->getName(), 'items' => []];
                $groupedItems[$key]['items'][] = $orderItem;
            }
        }

        $sections = [];
        foreach ($groupedItems as $group) {
            $totals = $this->productionTotalsCalculator->calculate($group['items']);
            $total = $totals[$group['name']] ?? throw new \LogicException('A production report section did not produce a total.');
            $lines = [];
            foreach ($group['items'] as $orderItem) {
                $order = $orderItem->getOrder();
                $unit = $orderItem->getUnit();
                if (null === $order || null === $unit) {
                    throw new \InvalidArgumentException('A production report line requires an order and unit.');
                }

                $eachQuantity = $this->productionQuantityCalculator->calculate($orderItem);
                $pickupAt = $order->getPickupAt();
                if (null === $pickupAt) {
                    throw new \InvalidArgumentException('A production report line requires a pickup time.');
                }

                $lines[] = new ProductionReportLine(
                    orderNumber: $order->getOrderNumber(),
                    pickupAt: $pickupAt->setTimezone($timezone),
                    customerName: $order->getCustomerName(),
                    quantity: $orderItem->getQuantity(),
                    unitName: $unit->getName(),
                    eachQuantity: $eachQuantity,
                    description: $orderItem->getDescription(),
                );
            }

            $sections[] = new ProductionReportSection($group['name'], $total, $lines);
        }

        return new ProductionReport($localStart, $sections);
    }
}
