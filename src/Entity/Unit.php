<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Unit
{
    use ActiveTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $abbreviation = null;

    #[Assert\Positive]
    #[ORM\Column]
    private int $eachEquivalent = 1;

    #[ORM\Column(options: ['default' => false])]
    private bool $packageUnit = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getAbbreviation(): ?string
    {
        return $this->abbreviation;
    }

    public function setAbbreviation(?string $abbreviation): static
    {
        $this->abbreviation = $abbreviation;

        return $this;
    }

    public function getEachEquivalent(): int
    {
        return $this->eachEquivalent;
    }

    public function setEachEquivalent(int $eachEquivalent): static
    {
        $this->eachEquivalent = $eachEquivalent;

        return $this;
    }

    public function isPackageUnit(): bool
    {
        return $this->packageUnit;
    }

    public function setPackageUnit(bool $packageUnit): static
    {
        $this->packageUnit = $packageUnit;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
