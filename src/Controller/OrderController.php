<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Order\BakeryClock;
use App\Application\Order\NewOrderInputFactory;
use App\Entity\Order;
use App\Form\Model\NewOrderData;
use App\Form\NewOrderType;
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
}
