<?php

namespace App\Entity;

use App\Repository\PrinterRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PrinterRepository::class)]
class Printer
{
    use TimestampableTrait;
    use ActiveTrait;

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
        $this->forLabels = $forLabels;

        return $this;
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
