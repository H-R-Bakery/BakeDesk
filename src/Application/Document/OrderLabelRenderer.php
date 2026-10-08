<?php

declare(strict_types=1);

namespace App\Application\Document;

use App\Application\Order\BakeryClock;
use App\Entity\Order;
use App\Entity\OrderItem;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Twig\Environment;

final class OrderLabelRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly DocumentRendererInterface $documentRenderer,
        #[Target('documents.storage')]
        private readonly FilesystemOperator $storage,
        private readonly BakeryClock $bakeryClock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function render(Order $order): RenderedDocument
    {
        try {
            $html = $this->twig->render('order/label.html.twig', [
                'order' => $order,
                'items' => $this->sortedItems($order),
                'pickup_at' => $this->asBakeryLocalDateTime($order->getPickupAt()),
                'ordered_at' => $this->asBakeryLocalDateTime($order->getOrderedAt()),
                'bakery_timezone' => $this->bakeryClock->getTimezoneName(),
            ]);
            $contents = $this->documentRenderer->renderHtmlToPdf($html);
            if (!str_starts_with($contents, '%PDF-')) {
                throw new \UnexpectedValueException('Gotenberg returned an invalid PDF document.');
            }

            $filename = sprintf('order-%s-%s.pdf', $this->safeOrderNumber($order), bin2hex(random_bytes(8)));
            $path = sprintf(
                'labels/%s/%s/%s',
                $order->getPickupAt()?->format('Y') ?? $this->bakeryClock->now()->format('Y'),
                $order->getPickupAt()?->format('m') ?? $this->bakeryClock->now()->format('m'),
                $filename,
            );
            $this->storage->write($path, $contents);

            return new RenderedDocument($path, 'application/pdf', $filename, $contents);
        } catch (\Throwable $exception) {
            $this->logger->error('Order label rendering failed.', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'exception' => $exception,
            ]);

            throw OrderLabelRenderingException::forOrder($order->getOrderNumber(), $exception);
        }
    }

    /** @return list<OrderItem> */
    private function sortedItems(Order $order): array
    {
        $items = $order->getItems()->toArray();
        usort($items, static fn (OrderItem $left, OrderItem $right): int => $left->getSortOrder() <=> $right->getSortOrder());

        return $items;
    }

    private function safeOrderNumber(Order $order): string
    {
        $safeOrderNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', $order->getOrderNumber()) ?? '';

        return trim($safeOrderNumber, '-') ?: 'unknown';
    }

    private function asBakeryLocalDateTime(?\DateTimeImmutable $dateTime): ?\DateTimeImmutable
    {
        if (null === $dateTime) {
            return null;
        }

        return new \DateTimeImmutable(
            $dateTime->format('Y-m-d H:i:s'),
            new \DateTimeZone($this->bakeryClock->getTimezoneName()),
        );
    }
}
