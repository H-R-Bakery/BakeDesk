<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Customer\CustomerResolver;
use App\Application\Order\OrderCreator;
use App\Application\Order\OrderInput;
use App\Application\Order\OrderItemInput;
use App\Application\Order\OrderNumberGenerator;
use App\Entity\Customer;
use App\Entity\Employee;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberUtil;
use Misd\PhoneNumberBundle\Doctrine\DBAL\Types\PhoneNumberType;
use PHPUnit\Framework\TestCase;

final class OrderCreatorTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    private CustomerRepository $customerRepository;

    private OrderCreator $orderCreator;

    private OrderRepository $orderRepository;

    protected function setUp(): void
    {
        if (!Type::hasType('phone_number')) {
            Type::addType('phone_number', PhoneNumberType::class);
        }

        $configuration = ORMSetup::createAttributeMetadataConfig([
            dirname(__DIR__, 2).'/src/Entity',
        ], true, 'bakedesk_order_creator_test');
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $configuration);
        $this->entityManager = new EntityManager($connection, $configuration);

        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, Employee::class, ProductType::class, Unit::class, Order::class, OrderItem::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry
            ->method('getManagerForClass')
            ->willReturn($this->entityManager);

        $this->customerRepository = new CustomerRepository($registry);
        $this->orderRepository = new OrderRepository($registry);
        $sequenceConnection = $this->createStub(Connection::class);
        $sequenceConnection->method('fetchOne')->willReturn('1000');
        $orderNumberGenerator = new OrderNumberGenerator($sequenceConnection);
        $this->orderCreator = new OrderCreator(
            $this->entityManager,
            new CustomerResolver($this->customerRepository),
            $this->orderRepository,
            $orderNumberGenerator,
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
    }

    public function testExistingCustomerIsReusedByCanonicalPhoneAndItsNameIsPreserved(): void
    {
        $existingCustomer = (new Customer())
            ->setName('Jennifer Smith')
            ->setPhone($this->phone('+18125551234'));
        $this->entityManager->persist($existingCustomer);
        $this->entityManager->flush();

        $order = $this->orderCreator->create($this->orderInput(
            customerName: 'Jenny Smith',
            customerPhone: $this->phone('(812) 555-1234'),
        ));

        self::assertSame($existingCustomer, $order->getCustomer());
        self::assertSame('Jennifer Smith', $existingCustomer->getName());
        self::assertSame('Jenny Smith', $order->getCustomerName());
        self::assertSame(1, $this->customerRepository->count());
    }

    public function testNewCustomerIsCreatedAndAttachedToTheOrder(): void
    {
        $order = $this->orderCreator->create($this->orderInput(
            customerName: 'Alex Baker',
            customerPhone: $this->phone('+18125551235'),
        ));

        self::assertInstanceOf(Customer::class, $order->getCustomer());
        $customer = $order->getCustomer();
        self::assertSame('Alex Baker', $customer->getName());
        self::assertSame($customer, $this->customerRepository->findOneByPhone($this->phone('+18125551235')));
        self::assertSame(1, $this->customerRepository->count());
    }

    public function testOrderSnapshotsRemainIndependentOfCustomerChanges(): void
    {
        $phone = $this->phone('+18125551236');
        $order = $this->orderCreator->create($this->orderInput(
            customerName: 'Taylor Baker',
            customerPhone: $phone,
        ));
        $customer = $order->getCustomer();

        self::assertNotNull($customer);
        $customer
            ->setName('Changed Customer')
            ->setPhone($this->phone('+18125551237'));

        self::assertSame('Taylor Baker', $order->getCustomerName());
        self::assertSame('+18125551236', $this->formatPhone($order->getCustomerPhone()));
    }

    public function testOrderFieldsAndItemsArePopulatedThroughEntityApis(): void
    {
        $employee = (new Employee())->setName('Morgan Baker');
        $productType = (new ProductType())->setName('Donuts');
        $unit = (new Unit())->setName('Dozen');
        $this->entityManager->persist($employee);
        $this->entityManager->persist($productType);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        $pickupAt = new \DateTimeImmutable('2026-10-10 09:30:00');
        $orderedAt = new \DateTimeImmutable('2026-10-07 14:15:00');
        $order = $this->orderCreator->create(new OrderInput(
            customerName: 'Jamie Baker',
            customerPhone: $this->phone('+18125551238'),
            employee: $employee,
            pickupAt: $pickupAt,
            orderedAt: $orderedAt,
            paid: true,
            notes: 'Call when ready',
            items: [
                new OrderItemInput($productType, '2.25', $unit, 'Glazed', 20),
                new OrderItemInput($productType, '1', $unit, 'Chocolate', 10),
            ],
        ));

        self::assertSame($employee, $order->getEmployee());
        self::assertSame($pickupAt, $order->getPickupAt());
        self::assertSame($orderedAt, $order->getOrderedAt());
        self::assertSame('1000', $order->getOrderNumber());
        self::assertTrue($order->isPaid());
        self::assertSame('Call when ready', $order->getNotes());
        self::assertSame('open', $order->getStatus()->value);
        self::assertCount(2, $order->getItems());

        $items = $order->getItems()->toArray();
        self::assertSame(['Glazed', 'Chocolate'], array_map(static fn (OrderItem $item): string => $item->getDescription(), $items));
        self::assertSame(['2.25', '1'], array_map(static fn (OrderItem $item): string => $item->getQuantity(), $items));
        self::assertSame([20, 10], array_map(static fn (OrderItem $item): int => $item->getSortOrder(), $items));
        foreach ($items as $item) {
            self::assertSame($order, $item->getOrder());
            self::assertSame($productType, $item->getProductType());
            self::assertSame($unit, $item->getUnit());
        }
    }

    public function testIndependentOrderCreationsReceiveDifferentGeneratedNumbers(): void
    {
        $sequenceConnection = $this->createStub(Connection::class);
        $sequenceConnection
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1000', '1001');
        $orderNumberGenerator = new OrderNumberGenerator($sequenceConnection);
        $this->orderCreator = new OrderCreator(
            $this->entityManager,
            new CustomerResolver($this->customerRepository),
            $this->orderRepository,
            $orderNumberGenerator,
        );

        $firstOrder = $this->orderCreator->create($this->orderInput(
            customerName: 'First Customer',
            customerPhone: $this->phone('+18125551241'),
        ));
        $secondOrder = $this->orderCreator->create($this->orderInput(
            customerName: 'Second Customer',
            customerPhone: $this->phone('+18125551242'),
        ));

        self::assertSame('1000', $firstOrder->getOrderNumber());
        self::assertSame('1001', $secondOrder->getOrderNumber());
        self::assertNotSame($firstOrder->getOrderNumber(), $secondOrder->getOrderNumber());
    }

    public function testFailedOrderDoesNotCommitTheNewCustomer(): void
    {
        $this->orderCreator->create($this->orderInput(
            customerName: 'First Customer',
            customerPhone: $this->phone('+18125551239'),
        ));

        try {
            $this->orderCreator->create($this->orderInput(
                customerName: 'Second Customer',
                customerPhone: $this->phone('+18125551240'),
            ));
            self::fail('The duplicate order number should make persistence fail.');
        } catch (\Throwable) {
            self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM customer'));
            self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM bakery_order'));
        }
    }

    private function orderInput(string $customerName, PhoneNumber $customerPhone): OrderInput
    {
        $employee = (new Employee())->setName('Counter Employee');
        $this->entityManager->persist($employee);
        $this->entityManager->flush();

        return new OrderInput(
            customerName: $customerName,
            customerPhone: $customerPhone,
            employee: $employee,
            pickupAt: new \DateTimeImmutable('2026-10-10 09:00:00'),
            orderedAt: new \DateTimeImmutable('2026-10-07 14:00:00'),
        );
    }

    private function phone(string $value): PhoneNumber
    {
        return PhoneNumberUtil::getInstance()->parse($value, 'US');
    }

    private function formatPhone(?PhoneNumber $phone): string
    {
        self::assertNotNull($phone);

        return PhoneNumberUtil::getInstance()->format($phone, \libphonenumber\PhoneNumberFormat::E164);
    }
}
