<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Index(name: 'idx_customer_phone', columns: ['phone'])]
class Customer
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
    #[ORM\Column(type: 'phone_number', nullable: false)]
	private PhoneNumber $phone;

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

    public function getPhone(): PhoneNumber
    {
        return $this->phone;
    }

    public function setPhone(PhoneNumber $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getPhoneString(): string
	{
		if (!$this->getPhone()) {
			return '';
		}
		$phoneUtil = PhoneNumberUtil::getInstance();

		return $phoneUtil->format($this->getPhone(), PhoneNumberFormat::E164);
	}

    public function setPhone1String(string $phone, string $country = 'US'): self
	{
		if (!$phone) {
			return $this;
		}
		$phoneUtil = PhoneNumberUtil::getInstance();
		/* @noinspection PhpUnhandledExceptionInspection */
		$this->phone = $phoneUtil->parse($phone, $country);

		return $this;
	}
}
