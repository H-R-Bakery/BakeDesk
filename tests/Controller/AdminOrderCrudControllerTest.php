<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Order\BakeryClock;
use App\Application\Order\OrderCanceller;
use App\Application\Order\OrderCompleter;
use App\Application\Order\OrderReopener;
use App\Application\Realtime\OrderRealtimePublisher;
use App\Controller\Admin\OrderCrudController;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PackagingRule;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Entity\User;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminOrderCrudControllerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private User $admin;

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);

        $this->admin = (new User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($this->admin);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testPaidOnlyEditPersistsAndPublishesTheFinalOrderState(): void
    {
        $order = $this->createOrder();
        $oldUpdatedAt = $order->getUpdatedAt();
        $publisher = $this->publisher(1, function (Update $update) use ($order): void {
            self::assertSame(['bakedesk:orders', 'bakedesk:order:'.$order->getId()], $update->getTopics());
            self::assertSame([
                'type' => 'order.updated',
                'orderId' => $order->getId(),
                'paid' => true,
                'status' => OrderStatus::OPEN->value,
            ], json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR));
        });

        $order->setPaid(true);
        $this->controller($publisher)->updateEntity($this->entityManager, $order);
        $saved = $this->reload($order);

        self::assertSame(OrderStatus::OPEN, $saved->getStatus());
        self::assertTrue($saved->isPaid());
        self::assertNotSame($oldUpdatedAt?->format('c'), $saved->getUpdatedAt()?->format('c'));
    }

    public function testPickupAndNotesEditPersistsWithoutChangingLifecycleOrPublishing(): void
    {
        $order = $this->createOrder(notes: 'Old note');
        $oldUpdatedAt = $order->getUpdatedAt();
        $newPickupAt = new \DateTimeImmutable('2026-10-12 15:30:00', new \DateTimeZone('UTC'));

        $order
            ->setPickupAt($newPickupAt)
            ->setNotes('New note');
        $this->controller($this->publisher(0))->updateEntity($this->entityManager, $order);
        $saved = $this->reload($order);

        self::assertSame(OrderStatus::OPEN, $saved->getStatus());
        self::assertSame('New note', $saved->getNotes());
        self::assertSame($newPickupAt->format('c'), $saved->getPickupAt()?->format('c'));
        self::assertNotSame($oldUpdatedAt?->format('c'), $saved->getUpdatedAt()?->format('c'));
    }

    public function testOpenToCompletedUsesLifecycleServiceAndPublishesFinalPaidState(): void
    {
        $order = $this->createOrder();
        $publisher = $this->publisher(1, function (Update $update): void {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(OrderStatus::COMPLETED->value, $payload['status']);
            self::assertTrue($payload['paid']);
        });

        $order->setStatus(OrderStatus::COMPLETED)->setPaid(true);
        $this->controller($publisher)->updateEntity($this->entityManager, $order);
        $saved = $this->reload($order);

        self::assertSame(OrderStatus::COMPLETED, $saved->getStatus());
        self::assertTrue($saved->isPaid());
    }

    public function testCompletedToOpenUsesLifecycleService(): void
    {
        $order = $this->createOrder(OrderStatus::COMPLETED);
        $order->setStatus(OrderStatus::OPEN);

        $this->controller($this->publisher(1))->updateEntity($this->entityManager, $order);

        self::assertSame(OrderStatus::OPEN, $this->reload($order)->getStatus());
    }

    public function testOpenToCancelledUsesLifecycleService(): void
    {
        $order = $this->createOrder();
        $order->setStatus(OrderStatus::CANCELLED);

        $this->controller($this->publisher(1))->updateEntity($this->entityManager, $order);

        self::assertSame(OrderStatus::CANCELLED, $this->reload($order)->getStatus());
    }

    #[DataProvider('invalidTransitionProvider')]
    public function testInvalidTransitionRestoresAllEditableFieldsAndAddsAnErrorFlash(OrderStatus $originalStatus, OrderStatus $submittedStatus): void
    {
        $oldPickupAt = new \DateTimeImmutable('2026-10-10 09:00:00', new \DateTimeZone('UTC'));
        $order = $this->createOrder($originalStatus, false, $oldPickupAt, 'Old note');
        $oldUpdatedAt = $order->getUpdatedAt();
        $request = Request::create('/admin/order/'.$order->getId().'/edit');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);

        try {
            $order
                ->setPickupAt(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('UTC')))
                ->setPaid(true)
                ->setNotes('Should not persist')
                ->setStatus($submittedStatus);

            $controller = $this->controller($this->publisher(0));
            $controller->setContainer(self::getContainer());
            $controller->updateEntity($this->entityManager, $order);

            $saved = $this->reload($order);
            self::assertSame($originalStatus, $saved->getStatus());
            self::assertFalse($saved->isPaid());
            self::assertSame('Old note', $saved->getNotes());
            self::assertSame($oldPickupAt->format('c'), $saved->getPickupAt()?->format('c'));
            self::assertSame($oldUpdatedAt?->format('c'), $saved->getUpdatedAt()?->format('c'));
            $session = $request->getSession();
            self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
            self::assertStringContainsString(
                'Order status change rejected',
                (string) $session->getFlashBag()->get('danger')[0],
            );
        } finally {
            $requestStack->pop();
        }
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function invalidTransitionProvider(): iterable
    {
        yield 'cancelled to open' => [OrderStatus::CANCELLED, OrderStatus::OPEN];
        yield 'completed to cancelled' => [OrderStatus::COMPLETED, OrderStatus::CANCELLED];
    }

    private function controller(OrderRealtimePublisher $publisher): OrderCrudController
    {
        $clock = self::getContainer()->get(BakeryClock::class);

        return new OrderCrudController(
            'America/Indiana/Indianapolis',
            'BakeDesk',
            $this->createStub(UrlGeneratorInterface::class),
            $clock,
            new OrderCompleter($this->entityManager, $clock, $publisher),
            new OrderReopener($this->entityManager, $clock, $publisher),
            new OrderCanceller($this->entityManager, $clock, $publisher),
            $publisher,
        );
    }

    private function publisher(int $expectedCalls, ?\Closure $assertion = null): OrderRealtimePublisher
    {
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects($this->exactly($expectedCalls))
            ->method('publish')
            ->willReturnCallback(function (Update $update) use ($assertion): string {
                $assertion?->__invoke($update);

                return 'update-id';
            });

        return new OrderRealtimePublisher($hub, $this->createStub(LoggerInterface::class));
    }

    private function createOrder(
        OrderStatus $status = OrderStatus::OPEN,
        bool $paid = false,
        ?\DateTimeImmutable $pickupAt = null,
        ?string $notes = null,
    ): Order {
        $customer = (new Customer())
            ->setName('Admin Edit Customer')
            ->setPhone(\libphonenumber\PhoneNumberUtil::getInstance()->parse('+18125550123', 'US'));
        $order = (new Order())
            ->setOrderNumber('9001')
            ->setCustomer($customer)
            ->setUser($this->admin)
            ->setPickupAt($pickupAt ?? new \DateTimeImmutable('2026-10-10 09:00:00', new \DateTimeZone('UTC')))
            ->setOrderedAt(new \DateTimeImmutable('2026-10-09 12:00:00', new \DateTimeZone('UTC')))
            ->setPaid($paid)
            ->setStatus($status)
            ->setNotes($notes)
            ->setUpdatedAt(new \DateTimeImmutable('2026-10-09 12:00:00', new \DateTimeZone('UTC')));
        $this->entityManager->persist($customer);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function reload(Order $order): Order
    {
        $id = $order->getId();
        self::assertNotNull($id);
        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(Order::class)->find($id);
        self::assertInstanceOf(Order::class, $saved);

        return $saved;
    }
}
