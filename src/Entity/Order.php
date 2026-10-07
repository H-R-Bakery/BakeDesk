<?php

namespace App\Entity;

use App\Model\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use libphonenumber\PhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'bakery_order')]
#[ORM\UniqueConstraint(name: 'uniq_bakery_order_order_number', columns: ['order_number'])]
#[ORM\Index(name: 'idx_bakery_order_pickup_at', columns: ['pickup_at'])]
#[ORM\Index(name: 'idx_bakery_order_status', columns: ['status'])]
#[ORM\Index(name: 'idx_bakery_order_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_bakery_order_employee', columns: ['employee_id'])]
class Order
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[ORM\Column(length: 50)]
    private string $orderNumber = '';

    #[Assert\NotNull]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Customer $customer = null;

    #[Assert\NotBlank]
    #[ORM\Column(length: 255)]
    private string $customerName = '';

    #[Assert\NotNull]
    #[ORM\Column(type: 'phone_number')]
    private ?PhoneNumber $customerPhone = null;

    #[Assert\NotNull]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Employee $employee = null;

    #[Assert\NotNull]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $pickupAt = null;

    #[Assert\NotNull]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $orderedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $paid = false;

    #[ORM\Column(type: Types::STRING, enumType: OrderStatus::class, length: 20)]
    private OrderStatus $status = OrderStatus::OPEN;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): static
    {
        $this->orderNumber = $orderNumber;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(Customer $customer): static
    {
        $this->customer = $customer;
        $this->customerName = $customer->getName();
        $this->customerPhone = clone $customer->getPhone();

        return $this;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function setCustomerName(string $customerName): static
    {
        $this->customerName = $customerName;

        return $this;
    }

    public function getCustomerPhone(): ?PhoneNumber
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(PhoneNumber $customerPhone): static
    {
        $this->customerPhone = clone $customerPhone;

        return $this;
    }

    public function getEmployee(): ?Employee
    {
        return $this->employee;
    }

    public function setEmployee(Employee $employee): static
    {
        $this->employee = $employee;

        return $this;
    }

    public function getPickupAt(): ?\DateTimeImmutable
    {
        return $this->pickupAt;
    }

    public function setPickupAt(\DateTimeImmutable $pickupAt): static
    {
        $this->pickupAt = $pickupAt;

        return $this;
    }

    public function getOrderedAt(): ?\DateTimeImmutable
    {
        return $this->orderedAt;
    }

    public function setOrderedAt(\DateTimeImmutable $orderedAt): static
    {
        $this->orderedAt = $orderedAt;

        return $this;
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }

    public function setPaid(bool $paid): static
    {
        $this->paid = $paid;

        return $this;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * @return Collection<int, OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): static
    {
        $previousOrder = $item->getOrder();
        if (null !== $previousOrder && $previousOrder !== $this) {
            $previousOrder->removeItem($item);
        }

        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }

        if ($item->getOrder() !== $this) {
            $item->setOrder($this);
        }

        return $this;
    }

    public function removeItem(OrderItem $item): static
    {
        if ($this->items->removeElement($item) && $item->getOrder() === $this) {
            $item->setOrder(null);
        }

        return $this;
    }
}
