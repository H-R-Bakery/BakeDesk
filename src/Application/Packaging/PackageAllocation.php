<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\Order;

final readonly class PackageAllocation
{
    /** @var list<PackageAllocationLine> */
    public array $lines;

    /** @param list<mixed> $lines */
    public function __construct(
        public int $packageNumber,
        public int $packageCount,
        array $lines,
        private ?Order $order = null,
    ) {
        self::assertValidPosition($packageNumber, $packageCount);
        if ([] === $lines) {
            throw new \InvalidArgumentException('A package allocation must contain at least one line.');
        }
        foreach ($lines as $line) {
            if (!$line instanceof PackageAllocationLine) {
                throw new \InvalidArgumentException('A package allocation may contain only package allocation lines.');
            }
        }
        $this->lines = $lines;
    }

    public function getOrder(): ?Order
    {
        if (null !== $this->order) {
            return $this->order;
        }

        return $this->lines[0]->orderItem->getOrder();
    }

    public function withOrder(Order $order): self
    {
        return new self($this->packageNumber, $this->packageCount, $this->lines, $order);
    }

    /** @return array{packageNumber: int, packageCount: int, lines: list<array{orderItemId: int|null, quantity: int, unitName: string, productTypeName: string, description: string}>} */
    public function toSnapshot(): array
    {
        return [
            'packageNumber' => $this->packageNumber,
            'packageCount' => $this->packageCount,
            'lines' => array_map(static fn (PackageAllocationLine $line): array => $line->toSnapshot(), $this->lines),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot, ?Order $order = null): self
    {
        $packageNumber = $snapshot['packageNumber'] ?? null;
        $packageCount = $snapshot['packageCount'] ?? null;
        $lines = $snapshot['lines'] ?? null;
        if (!is_int($packageNumber) || !is_int($packageCount) || !is_array($lines)) {
            throw new \InvalidArgumentException('A package snapshot contains invalid package metadata.');
        }

        $allocationLines = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new \InvalidArgumentException('A package snapshot contains an invalid line.');
            }
            $allocationLines[] = PackageAllocationLine::fromSnapshot($line);
        }

        return new self($packageNumber, $packageCount, $allocationLines, $order);
    }

    private static function assertValidPosition(int $number, int $count): void
    {
        if ($number < 1 || $count < 1 || $number > $count) {
            throw new \InvalidArgumentException('Package numbers must identify a valid order-wide package.');
        }
    }
}
