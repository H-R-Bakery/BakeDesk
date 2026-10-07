<?php

declare(strict_types=1);

namespace App\Application\Customer;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use libphonenumber\PhoneNumber;

final class CustomerResolver
{
    public function __construct(
        private CustomerRepository $customerRepository,
    ) {
    }

    public function resolve(string $name, PhoneNumber $phone, ?Customer $selectedCustomer = null): Customer
    {
        if (null !== $selectedCustomer && $selectedCustomer->isActive()) {
            return $selectedCustomer;
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
