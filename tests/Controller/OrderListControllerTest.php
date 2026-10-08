<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Order\BakeryClock;
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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderListControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    private KernelBrowser $client;

    private BakeryClock $bakeryClock;

    private Employee $employee;

    private ProductType $productType;

    private Unit $unit;

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->bakeryClock = self::getContainer()->get(BakeryClock::class);

        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, Employee::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);

        $this->employee = (new Employee())->setName('Alex Baker')->setSortOrder(10);
        $this->productType = (new ProductType())->setName('Donuts')->setSortOrder(10);
        $this->unit = (new Unit())->setName('Each')->setPackageUnit(true)->setSortOrder(10);
        $this->entityManager->persist($this->employee);
        $this->entityManager->persist($this->productType);
        $this->entityManager->persist($this->unit);
        $this->entityManager->flush();
    }

    public function testDefaultListShowsOnlyUpcomingOpenOrdersInPickupOrder(): void
    {
        $today = $this->bakeryClock->today();
        $this->createOrder('1001', $today->modify('-1 day')->setTime(10, 0), 'Past Open');
        $this->createOrder('1002', $today->modify('+1 day')->setTime(14, 0), 'Later Open');
        $this->createOrder('1003', $today->modify('+1 day')->setTime(9, 0), 'Earlier Open');
        $this->createOrder('1004', $today->modify('+1 day')->setTime(8, 0), 'Completed', OrderStatus::COMPLETED);
        $this->createOrder('1005', $today->modify('+1 day')->setTime(7, 0), 'Cancelled', OrderStatus::CANCELLED);

        $this->client->request('GET', '/orders');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Orders');
        self::assertSelectorExists('#orders-table[data-toggle="table"]');
        self::assertStringContainsString('bootstrap-table', (string) $this->client->getResponse()->getContent());
        self::assertSame(2, $this->client->getCrawler()->filter('#orders-table tbody tr')->count());
        self::assertStringContainsString('1003', $this->client->getCrawler()->filter('#orders-table tbody tr')->eq(0)->text());
        self::assertStringContainsString('1002', $this->client->getCrawler()->filter('#orders-table tbody tr')->eq(1)->text());
        self::assertStringNotContainsString('1001', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('1004', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('1005', (string) $this->client->getResponse()->getContent());
    }

    public function testSearchUsesOrderNumberSnapshotNameAndPhone(): void
    {
        $today = $this->bakeryClock->today();
        $this->createOrder('2001', $today->modify('-5 days')->setTime(10, 0), 'Morgan Smith', phone: '+18125550123');
        $this->createOrder('2002', $today->modify('-4 days')->setTime(10, 0), 'Other Customer', phone: '+18125550456');

        $this->client->request('GET', '/orders?q=2001');
        self::assertSelectorTextContains('#orders-table', 'Morgan Smith');
        self::assertStringNotContainsString('Other Customer', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/orders?q=Morgan');
        self::assertSelectorTextContains('#orders-table', '2001');

        $this->client->request('GET', '/orders?q=812-555-0123');
        self::assertSelectorTextContains('#orders-table', '2001');
    }

    public function testPickupDateStatusAndEmployeeFiltersAreAppliedInDatabase(): void
    {
        $secondEmployee = (new Employee())->setName('Jamie Cake')->setSortOrder(20);
        $this->entityManager->persist($secondEmployee);
        $this->entityManager->flush();
        $today = $this->bakeryClock->today();
        $this->createOrder('3001', $today->modify('+2 days')->setTime(9, 0), 'Open Date', employee: $this->employee);
        $this->createOrder('3002', $today->modify('+2 days')->setTime(10, 0), 'Completed Date', OrderStatus::COMPLETED, employee: $secondEmployee);
        $this->createOrder('3003', $today->modify('+3 days')->setTime(9, 0), 'Cancelled Date', OrderStatus::CANCELLED);

        $this->client->request('GET', '/orders?pickupDate='.$today->modify('+2 days')->format('Y-m-d'));
        self::assertSelectorTextContains('#orders-table', '3001');
        self::assertSelectorTextContains('#orders-table', '3002');
        self::assertStringNotContainsString('3003', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/orders?status=completed');
        self::assertSelectorTextContains('#orders-table', '3002');
        self::assertStringNotContainsString('3001', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/orders?employee='.$secondEmployee->getId());
        self::assertSelectorTextContains('#orders-table', '3002');
        self::assertStringNotContainsString('3001', (string) $this->client->getResponse()->getContent());
    }

    public function testDetailShowsSnapshotsEmployeePaymentStatusNotesAndItems(): void
    {
        $customer = $this->createCustomer('Original Name', '+18125550999');
        $order = $this->createOrder('4001', $this->bakeryClock->today()->modify('+1 day')->setTime(11, 30), 'Original Name', paid: true, notes: 'Call when ready', customer: $customer);
        $order->addItem(
            (new OrderItem())
                ->setProductType($this->productType)
                ->setQuantity('1')
                ->setUnit($this->unit)
                ->setDescription('Chocolate')
                ->setSortOrder(1),
        );
        $this->entityManager->flush();
        $customer->setName('Changed Current Name');
        $this->entityManager->flush();

        $this->client->request('GET', '/orders/'.$order->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Order 4001');
        self::assertSelectorTextContains('body', 'Original Name');
        self::assertStringNotContainsString('Changed Current Name', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('body', '(812) 555-0999');
        self::assertSelectorTextContains('body', 'Alex Baker');
        self::assertSelectorTextContains('body', 'Paid');
        self::assertSelectorTextContains('body', 'Open');
        self::assertSelectorTextContains('body', 'Call when ready');
        self::assertSelectorTextContains('body', 'Glazed');
        self::assertSelectorTextContains('body', 'Chocolate');
    }

    public function testEmptyStatesDistinguishUpcomingAndSearchResults(): void
    {
        $this->client->request('GET', '/orders');
        self::assertStringContainsString('No upcoming orders found.', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/orders?q=does-not-exist');
        self::assertStringContainsString('No orders matched your search.', (string) $this->client->getResponse()->getContent());
    }

    private function createOrder(
        string $number,
        \DateTimeImmutable $pickupAt,
        string $customerName,
        OrderStatus $status = OrderStatus::OPEN,
        ?Employee $employee = null,
        bool $paid = false,
        ?string $notes = null,
        ?Customer $customer = null,
        string $phone = '+18125550001',
    ): Order {
        $customer ??= $this->createCustomer($customerName, $phone);
        $order = (new Order())
            ->setOrderNumber($number)
            ->setCustomer($customer)
            ->setEmployee($employee ?? $this->employee)
            ->setPickupAt($pickupAt)
            ->setOrderedAt($this->bakeryClock->now())
            ->setStatus($status)
            ->setPaid($paid)
            ->setNotes($notes);
        $order->addItem(
            (new OrderItem())
                ->setProductType($this->productType)
                ->setQuantity('2')
                ->setUnit($this->unit)
                ->setDescription('Glazed')
                ->setSortOrder(0),
        );
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
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
