<?php

declare(strict_types=1);

namespace App\Tests\Application\Cleanup;

use App\Application\Cleanup\CleanupRetention;
use App\Application\Cleanup\CleanupService;
use App\Application\Order\BakeryClock;
use App\Command\CleanupCommand;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Entity\User;
use App\Model\OrderStatus;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use App\Repository\OrderRepository;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use League\Flysystem\FilesystemOperator;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CleanupServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private PrintJobRepository $printJobRepository;

    private OrderRepository $orderRepository;

    private BakeryClock $clock;

    private Printer $printer;

    private Customer $customer;

    private User $user;

    private ProductType $productType;

    private Unit $unit;

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);

        $this->printJobRepository = self::getContainer()->get(PrintJobRepository::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->clock = self::getContainer()->get(BakeryClock::class);

        $this->printer = (new Printer())
            ->setName('Test printer')
            ->setAddress('ipp://printer.test/print');
        $this->customer = (new Customer())
            ->setName('Cleanup Customer')
            ->setPhone(PhoneNumberUtil::getInstance()->parse('+18125550123'));
        $this->user = User::new('cleanup@example.test', 'Cleanup User', true);
        $this->productType = (new ProductType())->setName('Cookies');
        $this->unit = (new Unit())->setName('Each')->setEachEquivalent(1);
        foreach ([$this->printer, $this->customer, $this->user, $this->productType, $this->unit] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testOldTerminalJobsAreDeletedByStatusAndSharedDocumentsAreProtected(): void
    {
        $old = $this->nowUtc()->modify('-100 days');
        $recent = $this->nowUtc()->modify('-1 day');

        $oldShared = $this->createPrintJob(PrintJobStatus::COMPLETED, $old, 'labels/shared.pdf');
        $recentShared = $this->createPrintJob(PrintJobStatus::FAILED, $recent, 'labels/shared.pdf');
        $oldBothA = $this->createPrintJob(PrintJobStatus::CANCELLED, $old, 'labels/both.pdf');
        $oldBothB = $this->createPrintJob(PrintJobStatus::CANCELLED, $old, 'labels/both.pdf');
        $oldOnly = $this->createPrintJob(PrintJobStatus::COMPLETED, $old, 'labels/only.pdf');
        $oldMissing = $this->createPrintJob(PrintJobStatus::FAILED, $old, 'labels/missing.pdf');
        $oldQueued = $this->createPrintJob(PrintJobStatus::QUEUED, $old);
        $oldProcessing = $this->createPrintJob(PrintJobStatus::PROCESSING, $old);
        $oldRendered = $this->createPrintJob(PrintJobStatus::RENDERED, $old);
        $oldSubmitted = $this->createPrintJob(PrintJobStatus::SUBMITTED, $old);
        $this->entityManager->flush();
        $oldSharedId = $oldShared->getId();
        $recentSharedId = $recentShared->getId();
        $oldBothAId = $oldBothA->getId();
        $oldBothBId = $oldBothB->getId();
        $oldOnlyId = $oldOnly->getId();
        $oldMissingId = $oldMissing->getId();
        $oldQueuedId = $oldQueued->getId();
        $oldProcessingId = $oldProcessing->getId();
        $oldRenderedId = $oldRendered->getId();
        $oldSubmittedId = $oldSubmitted->getId();

        $files = [
            'labels/shared.pdf' => '%PDF-shared',
            'labels/both.pdf' => '%PDF-both',
            'labels/only.pdf' => '%PDF-only',
        ];
        $storage = $this->storage($files, 2);
        $result = $this->cleanup($storage)->run();

        self::assertSame(5, $result->eligiblePrintJobs);
        self::assertSame(5, $result->printJobsDeleted);
        self::assertSame(4, $result->candidateDocumentPaths);
        self::assertSame(3, $result->unreferencedDocumentPaths);
        self::assertSame(2, $result->documentsDeleted);
        self::assertSame(1, $result->missingDocuments);
        self::assertArrayHasKey('labels/shared.pdf', $files);
        self::assertArrayNotHasKey('labels/both.pdf', $files);
        self::assertArrayNotHasKey('labels/only.pdf', $files);

        $this->entityManager->clear();
        self::assertNull($this->printJobRepository->find($oldSharedId));
        self::assertInstanceOf(PrintJob::class, $this->printJobRepository->find($recentSharedId));
        self::assertNull($this->printJobRepository->find($oldBothAId));
        self::assertNull($this->printJobRepository->find($oldBothBId));
        self::assertNull($this->printJobRepository->find($oldOnlyId));
        self::assertNull($this->printJobRepository->find($oldMissingId));
        self::assertInstanceOf(PrintJob::class, $this->printJobRepository->find($oldQueuedId));
        self::assertInstanceOf(PrintJob::class, $this->printJobRepository->find($oldProcessingId));
        self::assertInstanceOf(PrintJob::class, $this->printJobRepository->find($oldRenderedId));
        self::assertInstanceOf(PrintJob::class, $this->printJobRepository->find($oldSubmittedId));
    }

    public function testEligibleOrdersDeleteItemsAndPreserveReferencedOrdersAndReferenceData(): void
    {
        $old = $this->nowUtc()->modify('-800 days');
        $recent = $this->nowUtc()->modify('-100 days');
        $oldCompleted = $this->createOrder('old-completed', $old, OrderStatus::COMPLETED);
        $oldCompletedItem = $oldCompleted->getItems()->first();
        $oldCancelled = $this->createOrder('old-cancelled', $old, OrderStatus::CANCELLED);
        $oldOpen = $this->createOrder('old-open', $old, OrderStatus::OPEN);
        $recentCompleted = $this->createOrder('recent-completed', $recent, OrderStatus::COMPLETED);
        $recentCancelled = $this->createOrder('recent-cancelled', $recent, OrderStatus::CANCELLED);
        $referencedOrder = $this->createOrder('referenced-order', $old, OrderStatus::COMPLETED);
        $itemReferencedOrder = $this->createOrder('item-referenced', $old, OrderStatus::CANCELLED);
        $itemReferencedOrder->addItem(
            (new OrderItem())
                ->setProductType($this->productType)
                ->setUnit($this->unit)
                ->setQuantity(1)
                ->setDescription('Second cleanup test item'),
        );
        $this->entityManager->flush();
        self::assertInstanceOf(OrderItem::class, $oldCompletedItem);
        $oldCompletedItemId = $oldCompletedItem->getId();

        $orderJob = $this->createPrintJob(PrintJobStatus::QUEUED, $old, null, $referencedOrder);
        $itemJob = $this->createPrintJob(PrintJobStatus::PROCESSING, $old, null, null, $itemReferencedOrder->getItems()->first());
        $this->entityManager->flush();
        $oldCompletedId = $oldCompleted->getId();
        $oldCancelledId = $oldCancelled->getId();
        $oldOpenId = $oldOpen->getId();
        $recentCompletedId = $recentCompleted->getId();
        $recentCancelledId = $recentCancelled->getId();
        $referencedOrderId = $referencedOrder->getId();
        $itemReferencedOrderId = $itemReferencedOrder->getId();
        $orderJobId = $orderJob->getId();
        $itemJobId = $itemJob->getId();

        $files = [];
        $result = $this->cleanup($this->storage($files, 0))->run();

        self::assertSame(2, $result->eligibleOrders);
        self::assertSame(2, $result->ordersDeleted);
        $this->entityManager->clear();
        self::assertNull($this->orderRepository->find($oldCompletedId));
        self::assertNull($this->orderRepository->find($oldCancelledId));
        self::assertInstanceOf(Order::class, $this->orderRepository->find($oldOpenId));
        self::assertInstanceOf(Order::class, $this->orderRepository->find($recentCompletedId));
        self::assertInstanceOf(Order::class, $this->orderRepository->find($recentCancelledId));
        self::assertInstanceOf(Order::class, $this->orderRepository->find($referencedOrderId));
        self::assertInstanceOf(Order::class, $this->orderRepository->find($itemReferencedOrderId));
        self::assertNull($this->entityManager->find(OrderItem::class, $oldCompletedItemId));
        self::assertNotNull($this->entityManager->find(Customer::class, $this->customer->getId()));
        self::assertNotNull($this->entityManager->find(User::class, $this->user->getId()));
        self::assertInstanceOf(PrintJob::class, $this->printJobRepository->find($orderJobId));
        self::assertInstanceOf(PrintJob::class, $this->printJobRepository->find($itemJobId));
    }

    public function testDryRunReportsEligibilityWithoutDeletingAnything(): void
    {
        $old = $this->nowUtc()->modify('-100 days');
        $order = $this->createOrder('dry-run-order', $this->nowUtc()->modify('-800 days'), OrderStatus::COMPLETED);
        $printJob = $this->createPrintJob(PrintJobStatus::COMPLETED, $old, 'labels/dry-run.pdf', $order, $order->getItems()->first());
        $this->entityManager->flush();
        $files = ['labels/dry-run.pdf' => '%PDF-dry-run'];
        $service = $this->cleanup($this->storage($files, 0));
        $command = new CleanupCommand($service, new LockFactory(new InMemoryStore()));
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Terminal PrintJobs eligible: 1', $tester->getDisplay());
        self::assertStringContainsString('Completed/cancelled Orders eligible: 1', $tester->getDisplay());
        self::assertStringContainsString('No data was deleted (--dry-run).', $tester->getDisplay());
        self::assertArrayHasKey('labels/dry-run.pdf', $files);
        self::assertInstanceOf(PrintJob::class, $this->printJobRepository->find($printJob->getId()));
        self::assertInstanceOf(Order::class, $this->orderRepository->find($order->getId()));
    }

    private function cleanup(FilesystemOperator $storage): CleanupService
    {
        return new CleanupService(
            $this->entityManager,
            $this->printJobRepository,
            $this->orderRepository,
            CleanupRetention::fromStrings('90', '730'),
            $this->clock,
            $storage,
        );
    }

    /** @param array<string, string> $files */
    private function storage(array &$files, int $expectedDeletes): FilesystemOperator
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage
            ->method('fileExists')
            ->willReturnCallback(static fn (string $path): bool => array_key_exists($path, $files));
        $storage
            ->expects(self::exactly($expectedDeletes))
            ->method('delete')
            ->willReturnCallback(static function (string $path) use (&$files): void {
                unset($files[$path]);
            });

        return $storage;
    }

    private function createPrintJob(
        PrintJobStatus $status,
        \DateTimeImmutable $createdAt,
        ?string $documentPath = null,
        ?Order $order = null,
        ?OrderItem $orderItem = null,
    ): PrintJob {
        $printJob = (new PrintJob())
            ->setPrinter($this->printer)
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus($status)
            ->setCreatedAt($createdAt)
            ->setDocumentPath($documentPath)
            ->setOrder($order)
            ->setOrderItem($orderItem);
        $this->entityManager->persist($printJob);

        return $printJob;
    }

    private function createOrder(string $orderNumber, \DateTimeImmutable $pickupAt, OrderStatus $status): Order
    {
        $order = (new Order())
            ->setOrderNumber($orderNumber)
            ->setCustomer($this->customer)
            ->setUser($this->user)
            ->setPickupAt($pickupAt)
            ->setOrderedAt($pickupAt)
            ->setStatus($status);
        $order->addItem(
            (new OrderItem())
                ->setProductType($this->productType)
                ->setUnit($this->unit)
                ->setQuantity(1)
                ->setDescription('Cleanup test item'),
        );
        $this->entityManager->persist($order);

        return $order;
    }

    private function nowUtc(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
