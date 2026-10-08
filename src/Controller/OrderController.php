<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Document\OrderLabelRenderer;
use App\Application\Document\OrderLabelRenderingException;
use App\Application\Order\BakeryClock;
use App\Application\Order\NewOrderInputFactory;
use App\Application\Order\OrderCanceller;
use App\Application\Order\OrderFormDataFactory;
use App\Application\Order\OrderSearchCriteria;
use App\Application\Order\OrderUpdater;
use App\Application\Packaging\OrderPackageCalculator;
use App\Application\Packaging\PackageAllocation;
use App\Application\Packaging\PackagingException;
use App\Application\Printing\LabelPrinterConfigurationException;
use App\Application\Printing\LabelPrintJobCreator;
use App\Entity\Order;
use App\Form\Model\NewOrderData;
use App\Form\NewOrderType;
use App\Model\OrderStatus;
use App\Repository\EmployeeRepository;
use App\Repository\OrderRepository;
use App\Repository\PrintJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    #[Route('/order/new', name: 'order_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        NewOrderInputFactory $newOrderInputFactory,
        BakeryClock $bakeryClock,
        LabelPrintJobCreator $labelPrintJobCreator,
        LoggerInterface $logger,
    ): Response {
        $data = new NewOrderData();
        $data->pickupDate = $bakeryClock->tomorrow();
        $data->pickupTime = $bakeryClock->tomorrow(morning: true);

        $form = $this->createForm(NewOrderType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $order = $newOrderInputFactory->create($data);

            try {
                $labelPrintJobCreator->createAndDispatch($order);
                $this->addFlash('success', sprintf('Order #%s saved. Label queued.', $order->getOrderNumber()));
            } catch (LabelPrinterConfigurationException $exception) {
                $logger->warning('Order label could not be queued because printer configuration is invalid.', [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'exception' => $exception,
                ]);
                $this->addFlash('warning', sprintf(
                    'Order #%s saved, but no default label printer is configured.',
                    $order->getOrderNumber(),
                ));
            } catch (PackagingException $exception) {
                $logger->warning('Order labels could not be queued because package allocation failed.', [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'exception' => $exception,
                ]);
                $this->addFlash('warning', sprintf(
                    'Order #%s saved, but labels could not be queued: %s',
                    $order->getOrderNumber(),
                    $exception->getMessage(),
                ));
            } catch (\Throwable $exception) {
                $logger->error('Order label could not be queued.', [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'exception' => $exception,
                ]);
                $this->addFlash('warning', sprintf(
                    'Order #%s saved, but its label could not be queued.',
                    $order->getOrderNumber(),
                ));
            }

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
    public function detail(int $id, OrderRepository $orderRepository, PrintJobRepository $printJobRepository, OrderPackageCalculator $orderPackageCalculator, BakeryClock $bakeryClock): Response
    {
        $order = $orderRepository->findForDetail($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        $packagingError = null;
        try {
            $packageAllocations = $orderPackageCalculator->calculate($order);
        } catch (PackagingException $exception) {
            $packageAllocations = [];
            $packagingError = $exception->getMessage();
        }

        return $this->render('order/detail.html.twig', [
            'order' => $order,
            'label_print_job' => $printJobRepository->findLatestLabelForOrder($order),
            'package_allocations' => $packageAllocations,
            'packaging_error' => $packagingError,
            'bakery_timezone' => $bakeryClock->getTimezoneName(),
        ]);
    }

    #[Route('/orders/{id<\d+>}/label', name: 'order_label', methods: ['GET'])]
    public function label(int $id, OrderRepository $orderRepository, OrderPackageCalculator $orderPackageCalculator, OrderLabelRenderer $orderLabelRenderer): Response
    {
        $order = $orderRepository->findForDetail($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        try {
            $allocations = $orderPackageCalculator->calculate($order);
            if ([] === $allocations) {
                throw new \LogicException('The order has no package allocations.');
            }
            $document = $orderLabelRenderer->render($allocations[0]);
        } catch (OrderLabelRenderingException $exception) {
            throw new ServiceUnavailableHttpException(null, 'The label preview is temporarily unavailable.', $exception);
        } catch (PackagingException $exception) {
            throw new ServiceUnavailableHttpException(null, 'The label preview is temporarily unavailable.', $exception);
        }

        $response = new Response($document->getContents());
        $response->headers->set('Content-Type', $document->mimeType);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->filename,
        ));

        return $response;
    }

    #[Route('/orders/{id<\d+>}/label/{itemId<\d+>}/{packageNumber<\d+>}', name: 'order_label_package', methods: ['GET'])]
    public function packageLabel(int $id, int $itemId, int $packageNumber, OrderRepository $orderRepository, OrderPackageCalculator $orderPackageCalculator, OrderLabelRenderer $orderLabelRenderer): Response
    {
        $order = $orderRepository->findForDetail($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        try {
            $allocation = null;
            foreach ($orderPackageCalculator->calculate($order) as $candidate) {
                if ($candidate->orderItem->getId() === $itemId && $candidate->packageNumber === $packageNumber) {
                    $allocation = $candidate;
                    break;
                }
            }
            if (!$allocation instanceof PackageAllocation) {
                throw $this->createNotFoundException();
            }

            $document = $orderLabelRenderer->render($allocation);
        } catch (OrderLabelRenderingException $exception) {
            throw new ServiceUnavailableHttpException(null, 'The label preview is temporarily unavailable.', $exception);
        } catch (PackagingException $exception) {
            throw new ServiceUnavailableHttpException(null, 'The label preview is temporarily unavailable.', $exception);
        }

        $response = new Response($document->getContents());
        $response->headers->set('Content-Type', $document->mimeType);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->filename,
        ));

        return $response;
    }

    #[Route('/orders/{id<\d+>}/edit', name: 'order_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        OrderRepository $orderRepository,
        OrderFormDataFactory $orderFormDataFactory,
        NewOrderInputFactory $newOrderInputFactory,
        OrderUpdater $orderUpdater,
    ): Response {
        $order = $orderRepository->findForDetail($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }
        if (OrderStatus::OPEN !== $order->getStatus()) {
            throw new AccessDeniedHttpException('Only open orders can be edited.');
        }

        $data = $orderFormDataFactory->fromOrder($order);
        $form = $this->createForm(NewOrderType::class, $data, [
            'action' => $this->generateUrl('order_edit', ['id' => $id]),
            'include_inactive' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $orderUpdater->update($order, $newOrderInputFactory->createInput($data));
            $this->addFlash('success', sprintf('Order #%s updated.', $order->getOrderNumber()));

            return $this->redirectToRoute('order_detail', ['id' => $id]);
        }

        return $this->render('order/edit.html.twig', [
            'form' => $form,
            'order' => $order,
            'autocomplete_url' => $this->generateUrl('customer_autocomplete'),
        ]);
    }

    #[Route('/orders/{id<\d+>}/cancel', name: 'order_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, OrderRepository $orderRepository, OrderCanceller $orderCanceller): Response
    {
        $order = $orderRepository->find($id);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }
        if (OrderStatus::OPEN !== $order->getStatus()) {
            throw new AccessDeniedHttpException('Only open orders can be cancelled.');
        }
        if (!$this->isCsrfTokenValid('cancel-order-'.$id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $orderCanceller->cancel($order);
        $this->addFlash('success', sprintf('Order #%s cancelled.', $order->getOrderNumber()));

        return $this->redirectToRoute('order_detail', ['id' => $id]);
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

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
