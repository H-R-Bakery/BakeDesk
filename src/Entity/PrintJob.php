<?php

namespace App\Entity;

use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use App\Repository\PrintJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PrintJobRepository::class)]
#[ORM\Index(name: 'idx_print_job_status', columns: ['status'])]
#[ORM\Index(name: 'idx_print_job_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_print_job_printer', columns: ['printer_id'])]
#[ORM\Index(name: 'idx_print_job_order', columns: ['order_id'])]
class PrintJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotNull]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Printer $printer = null;

    #[Assert\NotNull]
    #[ORM\Column(type: Types::STRING, enumType: PrintDocumentType::class, length: 20)]
    private ?PrintDocumentType $documentType = null;

    #[Assert\NotNull]
    #[ORM\Column(type: Types::STRING, enumType: PrintJobStatus::class, length: 20)]
    private PrintJobStatus $status = PrintJobStatus::QUEUED;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Order $order = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reportDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalJobId = null;

    #[Assert\GreaterThanOrEqual(0)]
    #[ORM\Column(options: ['default' => 0])]
    private int $attemptCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrinter(): ?Printer
    {
        return $this->printer;
    }

    public function setPrinter(Printer $printer): static
    {
        $this->printer = $printer;

        return $this;
    }

    public function getDocumentType(): ?PrintDocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(PrintDocumentType $documentType): static
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getStatus(): PrintJobStatus
    {
        return $this->status;
    }

    public function setStatus(PrintJobStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function getReportDate(): ?\DateTimeImmutable
    {
        return $this->reportDate;
    }

    public function setReportDate(?\DateTimeImmutable $reportDate): static
    {
        $this->reportDate = $reportDate;

        return $this;
    }

    public function getExternalJobId(): ?string
    {
        return $this->externalJobId;
    }

    public function setExternalJobId(?string $externalJobId): static
    {
        $this->externalJobId = $externalJobId;

        return $this;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setAttemptCount(int $attemptCount): static
    {
        $this->attemptCount = $attemptCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }
}
