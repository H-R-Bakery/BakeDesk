<?php

declare(strict_types=1);

namespace App\Application\Customer;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class CustomerResolver
{
    public function __construct(
        private CustomerRepository $customerRepository,
    ) {
    }

    public function resolve(string $name, PhoneNumber $phone, ?Customer $selectedCustomer = null): Customer
    {
        if (null !== $selectedCustomer && $selectedCustomer->isActive()) {
            $phoneUtil = PhoneNumberUtil::getInstance();
            if ($phoneUtil->format($selectedCustomer->getPhone(), PhoneNumberFormat::E164) === $phoneUtil->format($phone, PhoneNumberFormat::E164)) {
                return $selectedCustomer;
            }
        }

        $customer = $this->customerRepository->findOneByPhone($phone);
        if (null !== $customer) {
            return $customer;
        }

        $customer = (new Customer())
            ->setName($name)
            ->setPhone($phone);

        $this->customerRepository->save($customer);

        return $customer;
    }
}
