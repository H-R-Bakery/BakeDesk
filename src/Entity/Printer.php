<?php

namespace App\Entity;

use App\Repository\PrinterRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PrinterRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_printer_default_for_labels',
    columns: ['default_for_labels'],
    options: ['where' => 'default_for_labels = TRUE'],
)]
class Printer
{
    use TimestampableTrait;
    use ActiveTrait { setActive as private setActiveValue; }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[ORM\Column(length: 255)]
    private string $name = '';

    #[Assert\NotBlank]
    #[Assert\Url(protocols: ['ipp', 'ipps', 'http', 'https'])]
    #[ORM\Column(length: 2048)]
    private string $address = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $forLabels = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $defaultForLabels = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $forReports = false;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function isForLabels(): bool
    {
        return $this->forLabels;
    }

    public function setForLabels(bool $forLabels): static
    {
        if (!$forLabels && $this->defaultForLabels) {
            throw new \InvalidArgumentException('A default label printer must support labels.');
        }

        $this->forLabels = $forLabels;

        return $this;
    }

    public function isDefaultForLabels(): bool
    {
        return $this->defaultForLabels;
    }

    public function setDefaultForLabels(bool $defaultForLabels): static
    {
        if ($defaultForLabels && (!$this->isActive() || !$this->forLabels)) {
            throw new \InvalidArgumentException('A default label printer must be active and support labels.');
        }

        $this->defaultForLabels = $defaultForLabels;

        return $this;
    }

    public function setActive(bool $active): static
    {
        if (!$active && $this->defaultForLabels) {
            throw new \InvalidArgumentException('A default label printer must remain active.');
        }

        return $this->setActiveValue($active);
    }

    public function isForReports(): bool
    {
        return $this->forReports;
    }

    public function setForReports(bool $forReports): static
    {
        $this->forReports = $forReports;

        return $this;
    }
}
