<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CustomerRepository;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerController extends AbstractController
{
    #[Route('/customer/autocomplete', name: 'customer_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request, CustomerRepository $customerRepository): JsonResponse
    {
        $customers = $customerRepository->searchActive((string) $request->query->get('q', ''), 8);
        $phoneUtil = PhoneNumberUtil::getInstance();

        return $this->json(array_map(static function ($customer) use ($phoneUtil): array {
            return [
                'id' => $customer->getId(),
                'name' => $customer->getName(),
                'phone' => $phoneUtil->format($customer->getPhone(), PhoneNumberFormat::NATIONAL),
            ];
        }, $customers));
    }
}
