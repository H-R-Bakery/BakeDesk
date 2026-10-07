<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Order\OrderNumberGenerator;
use App\Entity\Customer;
use App\Entity\Employee;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
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
            [Customer::class, Employee::class, ProductType::class, Unit::class, Order::class, OrderItem::class, Printer::class, PrintJob::class],
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
        $this->entityManager->clear();
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['orderNumber' => '2000']);

        self::assertInstanceOf(Order::class, $order);
        self::assertSame('Taylor Baker', $order->getCustomerName());
        self::assertNotNull($order->getCustomerPhone());
        self::assertSame('+18125551234', PhoneNumberUtil::getInstance()->format($order->getCustomerPhone(), \libphonenumber\PhoneNumberFormat::E164));
        self::assertSame($employee->getId(), $order->getEmployee()?->getId());
        self::assertSame('2000', $order->getOrderNumber());
        self::assertSame('2026-10-10 09:30:00', $order->getPickupAt()?->format('Y-m-d H:i:s'));
        self::assertTrue($order->isPaid());
        self::assertSame('Call when ready', $order->getNotes());
        self::assertCount(2, $order->getItems());
        $items = $order->getItems()->toArray();
        self::assertSame(['Glazed', 'Chocolate'], array_map(static fn (OrderItem $item): string => $item->getDescription(), $items));
        self::assertSame(['2.25', '1'], array_map(static fn (OrderItem $item): string => $item->getQuantity(), $items));
        self::assertSame([0, 1], array_map(static fn (OrderItem $item): int => $item->getSortOrder(), $items));
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
        $active = (new Unit())->setName('Each')->setSortOrder(10)->setActive(true);
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
}
