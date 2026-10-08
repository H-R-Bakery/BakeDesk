<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PackagingRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: PackagingRuleRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_packaging_rule_product_unit_active',
    columns: ['product_type_id', 'unit_id', 'active'],
    options: ['where' => 'active = TRUE'],
)]
#[ORM\Index(name: 'idx_packaging_rule_product_unit', columns: ['product_type_id', 'unit_id'])]
class PackagingRule
{
    use TimestampableTrait;
    use ActiveTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotNull]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?ProductType $productType = null;

    #[Assert\NotNull]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Unit $unit = null;

    #[Assert\Positive]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $quantityPerPackage = '0.00';

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductType(): ?ProductType
    {
        return $this->productType;
    }

    public function setProductType(ProductType $productType): static
    {
        $this->productType = $productType;

        return $this;
    }

    public function getUnit(): ?Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getQuantityPerPackage(): string
    {
        return $this->quantityPerPackage;
    }

    public function setQuantityPerPackage(string $quantityPerPackage): static
    {
        $this->quantityPerPackage = $quantityPerPackage;

        return $this;
    }

    #[Assert\Callback]
    public function validateUnit(ExecutionContextInterface $context): void
    {
        if (null !== $this->unit && $this->unit->isPackageUnit()) {
            $context->buildViolation('Packaging rules apply only to non-package units.')
                ->atPath('unit')
                ->addViolation();
        }
    }
}
