<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Order\BakeryClock;
use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerController extends AbstractController
{
    #[Route('/customer/autocomplete', name: 'customer_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request, CustomerRepository $customerRepository): JsonResponse
    {
        $customers = $customerRepository->searchActive((string) $request->query->get('q', ''), 8);
        $phoneUtil = PhoneNumberUtil::getInstance();

        return $this->json(array_map(static function ($customer) use ($phoneUtil): array {
            $phone = $customer->getPhone();

            return [
                'id' => $customer->getId(),
                'name' => $customer->getName(),
                'phone' => null === $phone ? '' : $phoneUtil->format($phone, PhoneNumberFormat::NATIONAL),
            ];
        }, $customers));
    }

    #[Route('/customers/{id<\d+>}', name: 'customer_detail', methods: ['GET'])]
    public function detail(
        int $id,
        Request $request,
        CustomerRepository $customerRepository,
        OrderRepository $orderRepository,
        BakeryClock $bakeryClock,
    ): Response {
        $customer = $customerRepository->find($id);
        if (!$customer instanceof Customer) {
            throw $this->createNotFoundException();
        }

        $pageSize = 25;
        $total = $orderRepository->countForCustomerHistory($customer);
        $page = max(1, (int) $request->query->get('page', 1));
        if ($total > 0 && ($page - 1) * $pageSize >= $total) {
            $page = (int) ceil($total / $pageSize);
        }

        return $this->render('customer/detail.html.twig', [
            'customer' => $customer,
            'orders' => $orderRepository->findForCustomerHistory($customer, $page, $pageSize),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize)),
            'bakery_timezone' => $bakeryClock->getTimezoneName(),
        ]);
    }
}
