<?php

declare(strict_types=1);

namespace App\Application\Document;

use App\Application\Order\BakeryClock;
use App\Application\Packaging\PackageAllocation;
use App\Entity\Order;
use App\Entity\OrderItem;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%bakery_print_logo_path%')]
        private readonly string $printLogoPath = 'assets/Images/HRBakeryLogo_bw.svg',
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory = __DIR__.'/../../..',
    ) {
    }

    public function render(Order|PackageAllocation $subject): RenderedDocument
    {
        $order = $subject instanceof PackageAllocation ? $subject->orderItem->getOrder() : $subject;
        if (null === $order) {
            throw new \LogicException('A package label requires an order.');
        }

        try {
            $package = $subject instanceof PackageAllocation ? $subject : null;
            $items = null === $package ? $this->sortedItems($order) : [$package->orderItem];
            $this->assertItemsFit($items, $package);
            $html = $this->twig->render('order/label.html.twig', [
                'order' => $order,
                'items' => $items,
                'package' => $package,
                'pickup_at' => $this->asBakeryLocalDateTime($order->getPickupAt()),
                'ordered_at' => $this->asBakeryLocalDateTime($order->getOrderedAt()),
                'bakery_timezone' => $this->bakeryClock->getTimezoneName(),
                'logo_svg' => $this->loadLogoSvg(),
            ]);
            $contents = $this->documentRenderer->renderHtmlToPdf($html);
            if (!str_starts_with($contents, '%PDF-')) {
                throw new \UnexpectedValueException('Gotenberg returned an invalid PDF document.');
            }

            $filename = null === $package
                ? sprintf('order-%s-%s.pdf', $this->safeOrderNumber($order), $this->safePhoneNumber($order))
                : sprintf(
                    'order-%s-%s-item-%s-box-%d-of-%d.pdf',
                    $this->safeOrderNumber($order),
                    $this->safePhoneNumber($order),
                    $this->safeItemKey($package->orderItem),
                    $package->packageNumber,
                    $package->packageCount,
                );
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

    /**
     * The top section has a fixed-height item area. Keep enough room for the
     * CSS line boxes so an oversized order fails before Chromium can clip it.
     *
     * @param list<OrderItem> $items
     */
    private function assertItemsFit(array $items, ?PackageAllocation $package = null): void
    {
        $lineCount = 0;
        foreach ($items as $item) {
            $quantity = null !== $package ? $package->quantity : $item->getQuantity();
            $unit = null !== $package ? $package->unit : $item->getUnit();
            $heading = sprintf('%s %s %s', $quantity, $unit?->getName() ?? '', $item->getProductType()?->getName() ?? '');
            $lineCount += max(1, (int) ceil(mb_strlen($heading) / 42));
            $lineCount += max(1, (int) ceil(mb_strlen($item->getDescription()) / 30));
        }
        if (null !== $package && $package->packageCount > 1) {
            ++$lineCount;
        }

        if ($lineCount > 14) {
            throw new \LengthException('The order label item list does not fit in the fixed 4-inch top section.');
        }
    }

    private function loadLogoSvg(): string
    {
        $path = str_starts_with($this->printLogoPath, '/')
            ? $this->printLogoPath
            : $this->projectDirectory.'/'.$this->printLogoPath;
        $logo = file_get_contents($path);
        if (false === $logo || !str_contains($logo, '<svg')) {
            throw new \UnexpectedValueException('The configured label logo could not be loaded.');
        }

        return $logo;
    }

    private function safeOrderNumber(Order $order): string
    {
        $safeOrderNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', $order->getOrderNumber()) ?? '';

        return trim($safeOrderNumber, '-') ?: 'unknown';
    }

    private function safePhoneNumber(Order $order): string
    {
        $safePhoneNumber = preg_replace('/[^0-9]+/', '', $order->getCustomerPhone()->getNationalNumber()) ?? $order->getId();

        return trim($safePhoneNumber);
    }

    private function safeItemKey(OrderItem $item): string
    {
        return (string) ($item->getId() ?? $item->getSortOrder());
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
