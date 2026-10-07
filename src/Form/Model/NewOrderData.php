<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Employee;
use libphonenumber\PhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;

final class NewOrderData
{
    public function __construct()
    {
        $this->items = [new NewOrderItemData()];
    }

    #[Assert\NotBlank(message: 'Enter the customer name.')]
    public string $customerName = '';

    public ?int $customerId = null;

    #[Assert\NotNull(message: 'Enter a valid customer phone number.')]
    public ?PhoneNumber $customerPhone = null;

    #[Assert\NotNull(message: 'Choose the employee taking the order.')]
    public ?Employee $employee = null;

    #[Assert\NotNull(message: 'Choose a pickup date.')]
    public ?\DateTimeImmutable $pickupDate = null;

    #[Assert\NotNull(message: 'Choose a pickup time.')]
    public ?\DateTimeImmutable $pickupTime = null;

    public bool $paid = false;

    public ?string $notes = null;

    /**
     * @var list<NewOrderItemData>
     */
    #[Assert\Count(min: 1, minMessage: 'Add at least one order item.')]
    public array $items = [];
}
