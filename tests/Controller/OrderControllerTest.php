<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Document\DocumentRendererInterface;
use App\Application\Order\OrderNumberGenerator;
use App\Entity\Customer;
use App\Entity\Employee;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PackagingRule;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Model\OrderStatus;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    private KernelBrowser $client;

    private int $nextOrderNumber = 2000;

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, Employee::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(fn (): string => (string) $this->nextOrderNumber++);
        self::getContainer()->set(OrderNumberGenerator::class, new OrderNumberGenerator($connection));
    }

    public function testNewOrderPageShowsActiveReferenceData(): void
    {
        [$activeEmployee, $inactiveEmployee] = $this->createEmployees();
        [$activeProductType, $inactiveProductType] = $this->createProductTypes();
        [$activeUnit, $inactiveUnit] = $this->createUnits();

        $crawler = $this->client->request('GET', '/order/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'New order');
        self::assertSelectorExists(sprintf('input[value="%d"]', $activeEmployee->getId()));
        self::assertSelectorNotExists(sprintf('input[value="%d"]', $inactiveEmployee->getId()));
        self::assertSelectorTextContains('select[name="new_order[items][0][productType]"]', $activeProductType->getName());
        self::assertStringNotContainsString($inactiveProductType->getName(), $crawler->filter('select[name="new_order[items][0][productType]"]')->text());
        self::assertSelectorTextContains('select[name="new_order[items][0][unit]"]', $activeUnit->getName());
        self::assertStringNotContainsString($inactiveUnit->getName(), $crawler->filter('select[name="new_order[items][0][unit]"]')->text());
    }

    public function testCustomerAutocompleteMatchesNameAndPhoneAndLimitsResults(): void
    {
        $this->createCustomer('Jennifer Smith', '+18125550001');
        $this->createCustomer('Jennifer Jones', '+18125550002');
        $this->createCustomer('Jennifer Brown', '+18125550003');
        $this->createCustomer('Jennifer Davis', '+18125550004');
        $this->createCustomer('Jennifer Evans', '+18125550005');
        $this->createCustomer('Jennifer Flores', '+18125550006');
        $this->createCustomer('Jennifer Green', '+18125550007');
        $this->createCustomer('Jennifer Hall', '+18125550008');
        $this->createCustomer('Jennifer Irwin', '+18125550009');
        $this->createCustomer('Jennifer Jones', '+18125550010');
        $phoneMatch = $this->createCustomer('Alex Baker', '+18125551234');
        $this->createCustomer('Unrelated Customer', '+18125559999');

        $this->client->request('GET', '/customer/autocomplete?q=Jennifer');
        self::assertResponseIsSuccessful();
        $nameResults = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(8, $nameResults);
        self::assertSame(['id', 'name', 'phone'], array_keys($nameResults[0]));

        $this->client->request('GET', '/customer/autocomplete?q=812-555-1234');
        $phoneResults = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $phoneResults);
        self::assertSame($phoneMatch->getId(), $phoneResults[0]['id']);
        self::assertSame('Alex Baker', $phoneResults[0]['name']);
    }

    public function testValidOrderSubmissionRedirectsAndPersistsAllValues(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();

        $crawler = $this->client->request('GET', '/order/new');
        $form = $crawler->selectButton('Save order')->form([
            'new_order[customerName]' => 'Taylor Baker',
            'new_order[customerPhone]' => '8125551234',
            'new_order[employee]' => (string) $employee->getId(),
            'new_order[pickupDate]' => '2026-10-10',
            'new_order[pickupTime]' => '09:30',
            'new_order[paid]' => '1',
            'new_order[notes]' => 'Call when ready',
            'new_order[items][0][productType]' => (string) $productType->getId(),
            'new_order[items][0][quantity]' => '2.25',
            'new_order[items][0][unit]' => (string) $unit->getId(),
            'new_order[items][0][description]' => 'Glazed',
        ]);
        $values = $form->getPhpValues();
        $values['new_order']['items'][1] = [
            'productType' => (string) $productType->getId(),
            'quantity' => '1',
            'unit' => (string) $unit->getId(),
            'description' => 'Chocolate',
        ];

        $this->client->request('POST', '/order/new', $values);

        self::assertResponseRedirects('/order/1/created');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Order 2000');
        self::assertSelectorTextContains('.alert-warning', 'no default label printer is configured');
        $this->entityManager->clear();
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['orderNumber' => '2000']);

        self::assertInstanceOf(Order::class, $order);
        self::assertSame(0, $this->entityManager->getRepository(PrintJob::class)->count([]));
        self::assertSame('Taylor Baker', $order->getCustomerName());
        self::assertNotNull($order->getCustomerPhone());
        self::assertSame('+18125551234', PhoneNumberUtil::getInstance()->format($order->getCustomerPhone(), \libphonenumber\PhoneNumberFormat::E164));
        self::assertSame($employee->getId(), $order->getEmployee()?->getId());
        self::assertSame('2000', $order->getOrderNumber());
        self::assertSame('2026-10-10 13:30:00', $order->getPickupAt()?->format('Y-m-d H:i:s'));
        self::assertTrue($order->isPaid());
        self::assertSame('Call when ready', $order->getNotes());
        self::assertCount(2, $order->getItems());
        $items = $order->getItems()->toArray();
        self::assertSame(['Glazed', 'Chocolate'], array_map(static fn (OrderItem $item): string => $item->getDescription(), $items));
        self::assertSame(['2.25', '1'], array_map(static fn (OrderItem $item): string => $item->getQuantity(), $items));
        self::assertSame([0, 1], array_map(static fn (OrderItem $item): int => $item->getSortOrder(), $items));
    }

    public function testNewOrderCreatesQueuedLabelPrintJobWithoutRenderingDuringHttpRequest(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $printer = (new Printer())
            ->setName('Configured label printer')
            ->setAddress('ipp://printer.example/labels')
            ->setForLabels(true)
            ->setDefaultForLabels(true);
        $this->entityManager->persist($printer);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/order/new');
        $form = $crawler->selectButton('Save order')->form([
            'new_order[customerName]' => 'Queued Customer',
            'new_order[customerPhone]' => '8125551234',
            'new_order[employee]' => (string) $employee->getId(),
            'new_order[pickupDate]' => '2026-10-10',
            'new_order[pickupTime]' => '09:30',
            'new_order[items][0][productType]' => (string) $productType->getId(),
            'new_order[items][0][quantity]' => '1',
            'new_order[items][0][unit]' => (string) $unit->getId(),
            'new_order[items][0][description]' => 'Plain',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects('/order/1/created');
        $this->entityManager->clear();
        $printJob = $this->entityManager->getRepository(PrintJob::class)->findOneBy([]);

        self::assertInstanceOf(PrintJob::class, $printJob);
        self::assertSame(PrintDocumentType::LABEL, $printJob->getDocumentType());
        self::assertSame(PrintJobStatus::QUEUED, $printJob->getStatus());
        self::assertNull($printJob->getDocumentPath());
        self::assertSame(0, $printJob->getAttemptCount());
        self::assertSame($printer->getId(), $printJob->getPrinter()?->getId());

        $transport = self::getContainer()->get('messenger.transport.print');
        self::assertCount(1, $transport->getSent());
        self::assertSame(
            ['printJobId' => $printJob->getId()],
            get_object_vars($transport->getSent()[0]->getMessage()),
        );
    }

    public function testMissingPackagingRuleLeavesOrderSavedWithoutPartialLabelJobs(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        $dozen = (new Unit())->setName('Dozen')->setPackageUnit(true)->setActive(true);
        $each = (new Unit())->setName('Each')->setPackageUnit(false)->setActive(true);
        $this->entityManager->persist($dozen);
        $this->entityManager->persist($each);
        $this->entityManager->persist((new Printer())
            ->setName('Configured label printer')
            ->setAddress('ipp://printer.example/labels')
            ->setForLabels(true)
            ->setDefaultForLabels(true));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/order/new');
        $form = $crawler->selectButton('Save order')->form([
            'new_order[customerName]' => 'Packaging Failure Customer',
            'new_order[customerPhone]' => '8125551234',
            'new_order[employee]' => (string) $employee->getId(),
            'new_order[pickupDate]' => '2026-10-10',
            'new_order[pickupTime]' => '09:30',
            'new_order[items][0][productType]' => (string) $productType->getId(),
            'new_order[items][0][quantity]' => '1',
            'new_order[items][0][unit]' => (string) $dozen->getId(),
            'new_order[items][0][description]' => 'Glazed',
        ]);
        $values = $form->getPhpValues();
        $values['new_order']['items'][1] = [
            'productType' => (string) $productType->getId(),
            'quantity' => '30',
            'unit' => (string) $each->getId(),
            'description' => 'Chocolate',
        ];

        $this->client->request('POST', '/order/new', $values);
        self::assertResponseRedirects('/order/1/created');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'No active packaging rule exists for Donuts / Each');
        self::assertSame(1, $this->entityManager->getRepository(Order::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(PrintJob::class)->count([]));
    }

    public function testExistingCustomerIsReusedAndNewCustomerIsCreatedWhenNeeded(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $existingCustomer = $this->createCustomer('Existing Customer', '+18125551235');

        $this->submitOrder($employee, $productType, $unit, 'New Name', '8125551236', 1);
        $this->entityManager->clear();
        self::assertSame(2, $this->entityManager->getRepository(Customer::class)->count([]));

        $this->submitOrder($employee, $productType, $unit, 'Changed Name', '(812) 555-1235', 2);
        $this->entityManager->clear();
        self::assertSame(2, $this->entityManager->getRepository(Customer::class)->count([]));
        $reused = $this->entityManager->getRepository(Customer::class)->find($existingCustomer->getId());
        self::assertSame('Existing Customer', $reused->getName());
    }

    public function testInvalidSubmissionShowsErrorsAndPersistsNoOrder(): void
    {
        $crawler = $this->client->request('GET', '/order/new');
        $form = $crawler->selectButton('Save order')->form();

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Enter the customer name.');
        self::assertSame(0, $this->entityManager->getRepository(Order::class)->count([]));
    }

    public function testOpenOrderCanBeEditedAndKeepsItsOrderNumber(): void
    {
        [$employee, $replacementEmployee] = $this->createEmployees();
        $replacementEmployee->setActive(true);
        $this->entityManager->flush();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $customer = $this->createCustomer('Original Customer', '+18125551234');
        $order = $this->createOrder('3000', $employee, $productType, $unit, $customer);

        $crawler = $this->client->request('GET', '/orders/'.$order->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame('Original Customer', $crawler->filter('input[name="new_order[customerName]"]')->attr('value'));
        self::assertSame('3000', $order->getOrderNumber());

        $form = $crawler->selectButton('Save changes')->form();
        $values = $form->getPhpValues();
        $values['new_order']['customerName'] = 'Updated Customer';
        $values['new_order']['customerPhone'] = '8125551234';
        $values['new_order']['employee'] = (string) $replacementEmployee->getId();
        $values['new_order']['pickupDate'] = '2026-10-12';
        $values['new_order']['pickupTime'] = '14:45';
        $values['new_order']['paid'] = '1';
        $values['new_order']['notes'] = 'Updated notes';

        $this->client->request('POST', '/orders/'.$order->getId().'/edit', $values);

        self::assertResponseRedirects('/orders/'.$order->getId());
        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->getRepository(Order::class)->find($order->getId());

        self::assertInstanceOf(Order::class, $updatedOrder);
        self::assertSame('3000', $updatedOrder->getOrderNumber());
        self::assertSame('Updated Customer', $updatedOrder->getCustomerName());
        self::assertSame('+18125551234', PhoneNumberUtil::getInstance()->format($updatedOrder->getCustomerPhone(), \libphonenumber\PhoneNumberFormat::E164));
        self::assertSame($replacementEmployee->getId(), $updatedOrder->getEmployee()?->getId());
        self::assertSame('2026-10-12 18:45:00', $updatedOrder->getPickupAt()?->format('Y-m-d H:i:s'));
        self::assertTrue($updatedOrder->isPaid());
        self::assertSame('Updated notes', $updatedOrder->getNotes());
    }

    public function testEditingReassociatesToPhoneMatchWithoutRenamingExistingCustomer(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $originalCustomer = $this->createCustomer('Original Customer', '+18125551234');
        $replacementCustomer = $this->createCustomer('Replacement Customer', '+18125551235');
        $order = $this->createOrder('3001', $employee, $productType, $unit, $originalCustomer);

        $crawler = $this->client->request('GET', '/orders/'.$order->getId().'/edit');
        $form = $crawler->selectButton('Save changes')->form();
        $values = $form->getPhpValues();
        $values['new_order']['customerName'] = 'Order Snapshot Name';
        $values['new_order']['customerPhone'] = '(812) 555-1235';

        $this->client->request('POST', '/orders/'.$order->getId().'/edit', $values);

        self::assertResponseRedirects('/orders/'.$order->getId());
        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->getRepository(Order::class)->find($order->getId());
        $storedOriginalCustomer = $this->entityManager->getRepository(Customer::class)->find($originalCustomer->getId());
        $storedReplacementCustomer = $this->entityManager->getRepository(Customer::class)->find($replacementCustomer->getId());

        self::assertInstanceOf(Order::class, $updatedOrder);
        $updatedPhone = $updatedOrder->getCustomerPhone();
        self::assertNotNull($updatedPhone);
        self::assertSame($replacementCustomer->getId(), $updatedOrder->getCustomer()?->getId());
        self::assertSame('Order Snapshot Name', $updatedOrder->getCustomerName());
        self::assertSame('+18125551235', PhoneNumberUtil::getInstance()->format($updatedPhone, \libphonenumber\PhoneNumberFormat::E164));
        self::assertSame('Original Customer', $storedOriginalCustomer?->getName());
        self::assertSame('Replacement Customer', $storedReplacementCustomer?->getName());
    }

    public function testEditingWithUnknownPhoneCreatesAndAssociatesANewCustomer(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $originalCustomer = $this->createCustomer('Original Customer', '+18125551234');
        $order = $this->createOrder('3002', $employee, $productType, $unit, $originalCustomer);

        $crawler = $this->client->request('GET', '/orders/'.$order->getId().'/edit');
        $form = $crawler->selectButton('Save changes')->form();
        $values = $form->getPhpValues();
        $values['new_order']['customerName'] = 'New Customer';
        $values['new_order']['customerPhone'] = '8125551236';

        $this->client->request('POST', '/orders/'.$order->getId().'/edit', $values);

        self::assertResponseRedirects('/orders/'.$order->getId());
        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->getRepository(Order::class)->find($order->getId());
        $newCustomer = $updatedOrder?->getCustomer();

        self::assertInstanceOf(Customer::class, $newCustomer);
        self::assertNotSame($originalCustomer->getId(), $newCustomer->getId());
        self::assertSame('New Customer', $newCustomer->getName());
        self::assertSame('New Customer', $updatedOrder?->getCustomerName());
        self::assertSame(2, $this->entityManager->getRepository(Customer::class)->count([]));
    }

    public function testEditingSynchronizesItemsAndUsesVisualOrderForSortOrder(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $customer = $this->createCustomer('Customer', '+18125551234');
        $order = $this->createOrder('3003', $employee, $productType, $unit, $customer, itemCount: 2);
        $removedItem = $order->getItems()->toArray()[1];

        $crawler = $this->client->request('GET', '/orders/'.$order->getId().'/edit');
        $form = $crawler->selectButton('Save changes')->form();
        $values = $form->getPhpValues();
        $values['new_order']['items'][0]['quantity'] = '3';
        $values['new_order']['items'][0]['description'] = 'Updated glazed';
        unset($values['new_order']['items'][1]);
        $values['new_order']['items'][2] = [
            'productType' => (string) $productType->getId(),
            'quantity' => '1.5',
            'unit' => (string) $unit->getId(),
            'description' => 'Added chocolate',
        ];

        $this->client->request('POST', '/orders/'.$order->getId().'/edit', $values);

        self::assertResponseRedirects('/orders/'.$order->getId());
        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->getRepository(Order::class)->find($order->getId());
        $items = $updatedOrder?->getItems()->toArray() ?? [];

        self::assertCount(2, $items);
        self::assertSame(['Updated glazed', 'Added chocolate'], array_map(static fn (OrderItem $item): string => $item->getDescription(), $items));
        self::assertSame(['3', '1.5'], array_map(static fn (OrderItem $item): string => $item->getQuantity(), $items));
        self::assertSame([0, 1], array_map(static fn (OrderItem $item): int => $item->getSortOrder(), $items));
        self::assertNull($this->entityManager->getRepository(OrderItem::class)->find($removedItem->getId()));
    }

    public function testCancellationRequiresCsrfAndRetainsHistoricalData(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $customer = $this->createCustomer('Customer', '+18125551234');
        $order = $this->createOrder('3004', $employee, $productType, $unit, $customer);
        $orderId = $order->getId();
        $itemId = $order->getItems()->first()->getId();

        $crawler = $this->client->request('GET', '/orders/'.$orderId);
        self::assertSelectorExists('a[href="/orders/'.$orderId.'/edit"]');
        self::assertSelectorExists('button[data-bs-target="#cancel-order-modal"]');
        self::assertSelectorExists('form[action="/orders/'.$orderId.'/cancel"]');

        $this->client->request('POST', '/orders/'.$orderId.'/cancel');
        self::assertResponseStatusCodeSame(403);

        $cancelForm = $crawler->filter('form[action="/orders/'.$orderId.'/cancel"]');
        $token = $cancelForm->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/orders/'.$orderId.'/cancel', ['_token' => $token]);

        self::assertResponseRedirects('/orders/'.$orderId);
        $this->entityManager->clear();
        $cancelledOrder = $this->entityManager->getRepository(Order::class)->find($orderId);

        self::assertInstanceOf(Order::class, $cancelledOrder);
        self::assertSame(OrderStatus::CANCELLED, $cancelledOrder->getStatus());
        self::assertSame('3004', $cancelledOrder->getOrderNumber());
        self::assertSame($customer->getId(), $cancelledOrder->getCustomer()?->getId());
        self::assertSame($employee->getId(), $cancelledOrder->getEmployee()?->getId());
        self::assertCount(1, $cancelledOrder->getItems());
        self::assertInstanceOf(OrderItem::class, $this->entityManager->getRepository(OrderItem::class)->find($itemId));

        $this->client->request('GET', '/orders');
        self::assertSelectorNotExists('#orders-table');
        $this->client->request('GET', '/orders?status=cancelled');
        self::assertSelectorTextContains('#orders-table', '3004');
        $this->client->request('GET', '/orders?status=all&q=3004');
        self::assertSelectorTextContains('#orders-table', '3004');

        $this->client->request('GET', '/orders/'.$orderId);
        self::assertSelectorTextContains('body', 'Cancelled');
        self::assertSelectorNotExists('a[href="/orders/'.$orderId.'/edit"]');
        self::assertSelectorNotExists('#cancel-order-modal');
    }

    public function testCompletedAndCancelledOrdersCannotBeEdited(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $customer = $this->createCustomer('Customer', '+18125551234');
        $completed = $this->createOrder('3005', $employee, $productType, $unit, $customer, OrderStatus::COMPLETED);
        $cancelled = $this->createOrder('3006', $employee, $productType, $unit, $customer, OrderStatus::CANCELLED);

        $this->client->request('GET', '/orders/'.$completed->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/orders/'.$cancelled->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLabelPreviewReturnsStoredPdfWithoutCreatingPrintJob(): void
    {
        [$employee] = $this->createEmployees();
        [$productType] = $this->createProductTypes();
        [$unit] = $this->createUnits();
        $customer = $this->createCustomer('Preview Customer', '+18125551234');
        $order = $this->createOrder('3010', $employee, $productType, $unit, $customer);
        self::getContainer()->set(DocumentRendererInterface::class, new class implements DocumentRendererInterface {
            public function renderHtmlToPdf(string $html): string
            {
                return '%PDF-1.7 preview';
            }
        });

        $this->client->request('GET', '/orders/'.$order->getId().'/label');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('inline; filename=order-3010-', substr((string) $this->client->getResponse()->headers->get('Content-Disposition'), 0, 28));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->entityManager->getRepository(PrintJob::class)->count([]));
    }

    public function testUnknownOrderLabelReturnsNotFound(): void
    {
        $this->client->request('GET', '/orders/999/label');

        self::assertResponseStatusCodeSame(404);
    }

    private function submitOrder(Employee $employee, ProductType $productType, Unit $unit, string $name, string $phone, int $orderNumber): void
    {
        $crawler = $this->client->request('GET', '/order/new');
        $form = $crawler->selectButton('Save order')->form([
            'new_order[customerName]' => $name,
            'new_order[customerPhone]' => $phone,
            'new_order[employee]' => (string) $employee->getId(),
            'new_order[pickupDate]' => '2026-10-10',
            'new_order[pickupTime]' => '09:30',
            'new_order[items][0][productType]' => (string) $productType->getId(),
            'new_order[items][0][quantity]' => '1',
            'new_order[items][0][unit]' => (string) $unit->getId(),
            'new_order[items][0][description]' => 'Plain',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects(sprintf('/order/%d/created', $orderNumber));
    }

    /** @return array{Employee, Employee} */
    private function createEmployees(): array
    {
        $active = (new Employee())->setName('Active Employee')->setSortOrder(10)->setActive(true);
        $inactive = (new Employee())->setName('Inactive Employee')->setSortOrder(20)->setActive(false);
        $this->entityManager->persist($active);
        $this->entityManager->persist($inactive);
        $this->entityManager->flush();

        return [$active, $inactive];
    }

    /** @return array{ProductType, ProductType} */
    private function createProductTypes(): array
    {
        $active = (new ProductType())->setName('Donuts')->setSortOrder(10)->setActive(true);
        $inactive = (new ProductType())->setName('Inactive Type')->setSortOrder(20)->setActive(false);
        $this->entityManager->persist($active);
        $this->entityManager->persist($inactive);
        $this->entityManager->flush();

        return [$active, $inactive];
    }

    /** @return array{Unit, Unit} */
    private function createUnits(): array
    {
        $active = (new Unit())->setName('Each')->setPackageUnit(true)->setSortOrder(10)->setActive(true);
        $inactive = (new Unit())->setName('Inactive Unit')->setSortOrder(20)->setActive(false);
        $this->entityManager->persist($active);
        $this->entityManager->persist($inactive);
        $this->entityManager->flush();

        return [$active, $inactive];
    }

    private function createCustomer(string $name, string $phone): Customer
    {
        $customer = (new Customer())
            ->setName($name)
            ->setPhone(PhoneNumberUtil::getInstance()->parse($phone, 'US'));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function createOrder(
        string $number,
        Employee $employee,
        ProductType $productType,
        Unit $unit,
        Customer $customer,
        OrderStatus $status = OrderStatus::OPEN,
        int $itemCount = 1,
    ): Order {
        $order = (new Order())
            ->setOrderNumber($number)
            ->setCustomer($customer)
            ->setEmployee($employee)
            ->setPickupAt(new \DateTimeImmutable('2026-10-10 09:00:00', new \DateTimeZone('America/Indiana/Indianapolis')))
            ->setOrderedAt(new \DateTimeImmutable('2026-10-07 14:00:00', new \DateTimeZone('America/Indiana/Indianapolis')))
            ->setStatus($status);

        for ($index = 0; $index < $itemCount; ++$index) {
            $order->addItem(
                (new OrderItem())
                    ->setProductType($productType)
                    ->setQuantity((string) ($index + 1))
                    ->setUnit($unit)
                    ->setDescription(0 === $index ? 'Glazed' : 'Chocolate')
                    ->setSortOrder($index),
            );
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
