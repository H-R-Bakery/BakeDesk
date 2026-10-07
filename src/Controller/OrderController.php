<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Order\BakeryClock;
use App\Application\Order\NewOrderInputFactory;
use App\Application\Order\OrderSearchCriteria;
use App\Entity\Order;
use App\Form\Model\NewOrderData;
use App\Form\NewOrderType;
use App\Model\OrderStatus;
use App\Repository\EmployeeRepository;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    #[Route('/order/new', name: 'order_new', methods: ['GET', 'POST'])]
    public function new(Request $request, NewOrderInputFactory $newOrderInputFactory, BakeryClock $bakeryClock): Response
    {
        $data = new NewOrderData();
        $data->pickupDate = $bakeryClock->tomorrow();
        $data->pickupTime = $bakeryClock->tomorrow(morning: true);

        $form = $this->createForm(NewOrderType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $order = $newOrderInputFactory->create($data);

            return $this->redirectToRoute('order_created', ['id' => $order->getId()]);
        }

        return $this->render('order/new.html.twig', [
            'form' => $form,
            'autocomplete_url' => $this->generateUrl('customer_autocomplete'),
        ]);
    }

    #[Route('/orders', name: 'order_index', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orderRepository, EmployeeRepository $employeeRepository, BakeryClock $bakeryClock): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $pickupDateValue = trim((string) $request->query->get('pickupDate', ''));
        $pickupDate = $this->parsePickupDate($pickupDateValue, $bakeryClock);
        $statusValue = strtolower(trim((string) $request->query->get('status', '')));
        $statusProvided = $request->query->has('status');
        $status = '' !== $statusValue && 'all' !== $statusValue ? OrderStatus::tryFrom($statusValue) : null;
        $employeeIdValue = filter_var($request->query->get('employee'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $employeeId = false === $employeeIdValue ? null : $employeeIdValue;
        $criteria = new OrderSearchCriteria(
            query: $query,
            pickupDate: $pickupDate,
            status: $status,
            statusProvided: $statusProvided,
            employeeId: $employeeId,
            upcomingFrom: $bakeryClock->today(),
        );
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = 25;
        $total = $orderRepository->countForList($criteria);

        if ($total > 0 && ($page - 1) * $pageSize >= $total) {
            $page = (int) ceil($total / $pageSize);
        }

        return $this->render('order/index.html.twig', [
            'rows' => $orderRepository->searchForList($criteria, $page, $pageSize),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize)),
            'query' => $query,
            'pickup_date' => $pickupDateValue,
            'status' => $statusValue,
            'employee_id' => $employeeId,
            'employees' => $employeeRepository->findAllOrdered(),
            'is_default_upcoming' => $criteria->isDefaultUpcoming(),
            'bakery_timezone' => $bakeryClock->getTimezoneName(),
        ]);
    }

    #[Route('/orders/{id<\d+>}', name: 'order_detail', methods: ['GET'])]
    public function detail(int $id, OrderRepository $orderRepository, BakeryClock $bakeryClock): Response
    {
        $order = $orderRepository->findForDetail($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        return $this->render('order/detail.html.twig', [
            'order' => $order,
            'bakery_timezone' => $bakeryClock->getTimezoneName(),
        ]);
    }

    #[Route('/order/{id<\d+>}/created', name: 'order_created', methods: ['GET'])]
    public function created(int $id, OrderRepository $orderRepository, BakeryClock $bakeryClock): Response
    {
        $order = $orderRepository->find($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        return $this->render('order/created.html.twig', [
            'order' => $order,
            'bakery_timezone' => $bakeryClock->getTimezoneName(),
        ]);
    }

    private function parsePickupDate(string $value, BakeryClock $bakeryClock): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone($bakeryClock->getTimezoneName()));
        $errors = \DateTimeImmutable::getLastErrors();

        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
