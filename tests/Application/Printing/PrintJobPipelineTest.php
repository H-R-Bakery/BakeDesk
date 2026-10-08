<?php

declare(strict_types=1);

namespace App\Tests\Application\Printing;

use App\Application\Document\DocumentRendererInterface;
use App\Application\Document\OrderLabelRenderer;
use App\Application\Order\BakeryClock;
use App\Application\Packaging\OrderPackageCalculator;
use App\Application\Printing\IppJobStateMapper;
use App\Application\Printing\LabelPrinterConfigurationException;
use App\Application\Printing\LabelPrinterResolver;
use App\Application\Printing\LabelPrintJobCreator;
use App\Application\Printing\PrinterClientInterface;
use App\Application\Printing\PrinterSubmissionException;
use App\Application\Printing\PrintJobState;
use App\Application\Printing\PrintJobStatusSnapshot;
use App\Application\Printing\PrintSubmission;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PackagingRule;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Entity\User;
use App\Message\ProcessPrintJob;
use App\Message\RefreshPrintJobStatus;
use App\MessageHandler\ProcessPrintJobHandler;
use App\MessageHandler\RefreshPrintJobStatusHandler;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use App\Repository\PrinterRepository;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use League\Flysystem\FilesystemOperator;
use libphonenumber\PhoneNumberUtil;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

final class PrintJobPipelineTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private PrintJobRepository $printJobRepository;

    private PrinterRepository $printerRepository;

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
        $this->printJobRepository = self::getContainer()->get(PrintJobRepository::class);
        $this->printerRepository = self::getContainer()->get(PrinterRepository::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testActiveLabelCapableDefaultResolvesAndMissingDefaultFailsClearly(): void
    {
        $resolver = new LabelPrinterResolver($this->printerRepository);

        $this->expectException(LabelPrinterConfigurationException::class);
        $resolver->resolve();
    }

    public function testConfiguredDefaultLabelPrinterResolves(): void
    {
        $printer = $this->createDefaultPrinter();

        self::assertSame($printer, (new LabelPrinterResolver($this->printerRepository))->resolve());
    }

    public function testInvalidDefaultRowsAreRejectedByResolver(): void
    {
        $printer = (new Printer())
            ->setName('Inactive printer')
            ->setAddress('ipp://printer.example/inactive')
            ->setForLabels(true);
        $this->entityManager->persist($printer);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE printer SET default_for_labels = TRUE, active = FALSE WHERE id = :id',
            ['id' => $printer->getId()],
        );
        $this->entityManager->clear();

        $this->expectException(LabelPrinterConfigurationException::class);
        (new LabelPrinterResolver($this->printerRepository))->resolve();
    }

    public function testDefaultThatDoesNotSupportLabelsIsRejectedByResolver(): void
    {
        $printer = (new Printer())
            ->setName('Reports printer')
            ->setAddress('ipp://printer.example/reports');
        $this->entityManager->persist($printer);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE printer SET default_for_labels = TRUE WHERE id = :id',
            ['id' => $printer->getId()],
        );
        $this->entityManager->clear();

        $this->expectException(LabelPrinterConfigurationException::class);
        (new LabelPrinterResolver($this->printerRepository))->resolve();
    }

    public function testDatabaseUniqueConstraintRejectsMultipleConfiguredDefaults(): void
    {
        $this->createDefaultPrinter();
        $second = (new Printer())
            ->setName('Second label printer')
            ->setAddress('ipp://printer.example/second-labels')
            ->setForLabels(true)
            ->setDefaultForLabels(true);
        $this->entityManager->persist($second);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testCreatorPersistsOneQueuedJobPerPhysicalPackageAndDispatchesOnlyIdentifiers(): void
    {
        $printer = $this->createDefaultPrinter();
        $order = $this->createOrder();
        $dispatchedJobIds = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::callback(function (object $message) use (&$dispatchedJobIds): bool {
                self::assertInstanceOf(ProcessPrintJob::class, $message);
                self::assertSame(['printJobId' => $message->printJobId], get_object_vars($message));
                $dispatchedJobIds[] = $message->printJobId;

                return true;
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $creator = new LabelPrintJobCreator(
            $this->entityManager,
            $this->printJobRepository,
            new LabelPrinterResolver($this->printerRepository),
            self::getContainer()->get(OrderPackageCalculator::class),
            $bus,
        );
        $printJobs = $creator->createAndDispatch($order);

        self::assertCount(2, $printJobs);
        self::assertSame([1, 2], array_map(static fn (PrintJob $printJob): ?int => $printJob->getPackageNumber(), $printJobs));
        self::assertSame([2, 2], array_map(static fn (PrintJob $printJob): ?int => $printJob->getPackageCount(), $printJobs));
        self::assertSame(['1', '1'], array_map(static fn (PrintJob $printJob): ?string => $printJob->getPackageQuantity(), $printJobs));
        self::assertSame(array_map(static fn (PrintJob $printJob): ?int => $printJob->getId(), $printJobs), $dispatchedJobIds);
        foreach ($printJobs as $printJob) {
            self::assertNotNull($printJob->getId());
            self::assertSame($printer, $printJob->getPrinter());
            self::assertSame($order, $printJob->getOrder());
            self::assertSame(PrintDocumentType::LABEL, $printJob->getDocumentType());
            self::assertSame(PrintJobStatus::QUEUED, $printJob->getStatus());
            self::assertSame(0, $printJob->getAttemptCount());
            self::assertNull($printJob->getDocumentPath());
        }
    }

    public function testHandlerRendersStoresLogicalPathAndSubmitsOnce(): void
    {
        $this->createDefaultPrinter();
        $order = $this->createOrder();
        $printJob = (new PrintJob())
            ->setPrinter($this->printerRepository->findConfiguredLabelDefaults()[0])
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus(PrintJobStatus::QUEUED)
            ->setOrder($order);
        $this->entityManager->persist($printJob);
        $this->entityManager->flush();

        $storage = $this->createMock(FilesystemOperator::class);
        $storage
            ->expects(self::once())
            ->method('write')
            ->with(
                self::matchesRegularExpression('#^labels/2026/10/order-1234-[0-9]{10}\.pdf$#'),
                '%PDF-1.7 test label',
            );
        $pdfRenderer = $this->createStub(DocumentRendererInterface::class);
        $pdfRenderer->method('renderHtmlToPdf')->willReturn('%PDF-1.7 test label');
        $labelRenderer = new OrderLabelRenderer(
            self::getContainer()->get(Environment::class),
            $pdfRenderer,
            $storage,
            self::getContainer()->get(BakeryClock::class),
            $this->createStub(LoggerInterface::class),
        );

        $printerClient = $this->createMock(PrinterClientInterface::class);
        $printerClient
            ->expects(self::once())
            ->method('submitPdf')
            ->with(
                self::isInstanceOf(Printer::class),
                self::matchesRegularExpression('#^labels/2026/10/order-1234-[0-9]{10}\.pdf$#'),
                'BakeDesk Order #1234',
            )
            ->willReturn(new PrintSubmission('42', new PrintJobStatusSnapshot(PrintJobState::PENDING)));
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $message): bool => $message instanceof RefreshPrintJobStatus && 0 === $message->checkNumber))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        (new ProcessPrintJobHandler(
            $this->entityManager,
            $this->printJobRepository,
            $labelRenderer,
            self::getContainer()->get(BakeryClock::class),
            $printerClient,
            new IppJobStateMapper(),
            $messageBus,
            $this->createStub(LoggerInterface::class),
        ))(new ProcessPrintJob($printJob->getId()));

        $this->entityManager->clear();
        $stored = $this->printJobRepository->find($printJob->getId());
        self::assertInstanceOf(PrintJob::class, $stored);
        self::assertSame(PrintJobStatus::SUBMITTED, $stored->getStatus());
        self::assertSame(1, $stored->getAttemptCount());
        self::assertMatchesRegularExpression('#^labels/2026/10/order-1234-[0-9]{10}\.pdf$#', (string) $stored->getDocumentPath());
        self::assertNotNull($stored->getSubmittedAt());
        self::assertSame('42', $stored->getExternalJobId());
        self::assertNull($stored->getCompletedAt());

        // Redelivery after IPP acceptance does not create a second document or submission.
        (new ProcessPrintJobHandler(
            $this->entityManager,
            $this->printJobRepository,
            $labelRenderer,
            self::getContainer()->get(BakeryClock::class),
            $printerClient,
            new IppJobStateMapper(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(LoggerInterface::class),
        ))(new ProcessPrintJob($printJob->getId()));
    }

    public function testCreatorCreatesOneLabelForEveryPackageAcrossOrderItems(): void
    {
        $printer = $this->createDefaultPrinter();
        $order = $this->createOrder();
        $cookies = (new ProductType())->setName('Cookies');
        $tray = (new Unit())->setName('Tray')->setPackageUnit(true);
        $this->entityManager->persist($cookies);
        $this->entityManager->persist($tray);
        $this->entityManager->flush();
        $order->addItem(
            (new OrderItem())
                ->setProductType($cookies)
                ->setQuantity('1')
                ->setUnit($tray)
                ->setDescription('Chocolate Chip')
                ->setSortOrder(20),
        );
        $this->entityManager->flush();

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))
            ->method('dispatch')
            ->with(self::isInstanceOf(ProcessPrintJob::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $creator = new LabelPrintJobCreator(
            $this->entityManager,
            $this->printJobRepository,
            new LabelPrinterResolver($this->printerRepository),
            self::getContainer()->get(OrderPackageCalculator::class),
            $bus,
        );

        $printJobs = $creator->createAndDispatch($order);

        self::assertCount(3, $printJobs);
        self::assertSame($printer, $printJobs[0]->getPrinter());
        self::assertSame([1, 2, 1], array_map(static fn (PrintJob $job): ?int => $job->getPackageNumber(), $printJobs));
        self::assertSame([2, 2, 1], array_map(static fn (PrintJob $job): ?int => $job->getPackageCount(), $printJobs));
        self::assertSame(['1', '1', '1'], array_map(static fn (PrintJob $job): ?string => $job->getPackageQuantity(), $printJobs));
        self::assertSame($order->getItems()->toArray()[0], $printJobs[0]->getOrderItem());
        self::assertSame($order->getItems()->toArray()[1], $printJobs[2]->getOrderItem());
    }

    public function testCreatorStoresRuleBasedPartialPackageQuantities(): void
    {
        $this->createDefaultPrinter();
        $order = $this->createOrder();
        $cookies = (new ProductType())->setName('Cookies');
        $each = (new Unit())->setName('Each');
        $this->entityManager->persist($cookies);
        $this->entityManager->persist($each);
        $this->entityManager->flush();
        $rule = (new PackagingRule())
            ->setProductType($cookies)
            ->setUnit($each)
            ->setQuantityPerPackage('24');
        $this->entityManager->persist($rule);
        $order->addItem(
            (new OrderItem())
                ->setProductType($cookies)
                ->setQuantity('30')
                ->setUnit($each)
                ->setDescription('Chocolate Chip')
                ->setSortOrder(20),
        );
        $this->entityManager->flush();

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(4))
            ->method('dispatch')
            ->with(self::isInstanceOf(ProcessPrintJob::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $creator = new LabelPrintJobCreator(
            $this->entityManager,
            $this->printJobRepository,
            new LabelPrinterResolver($this->printerRepository),
            self::getContainer()->get(OrderPackageCalculator::class),
            $bus,
        );

        $printJobs = $creator->createAndDispatch($order);
        $ruleJobs = array_slice($printJobs, -2);

        self::assertSame(['24', '6'], array_map(static fn (PrintJob $job): ?string => $job->getPackageQuantity(), $ruleJobs));
        self::assertSame([1, 2], array_map(static fn (PrintJob $job): ?int => $job->getPackageNumber(), $ruleJobs));
        self::assertSame([2, 2], array_map(static fn (PrintJob $job): ?int => $job->getPackageCount(), $ruleJobs));
    }

    public function testHandlerMarksRenderingFailureAndRethrowsForMessengerRetry(): void
    {
        $this->createDefaultPrinter();
        $order = $this->createOrder();
        $printJob = (new PrintJob())
            ->setPrinter($this->printerRepository->findConfiguredLabelDefaults()[0])
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setOrder($order);
        $this->entityManager->persist($printJob);
        $this->entityManager->flush();

        $pdfRenderer = $this->createMock(DocumentRendererInterface::class);
        $pdfRenderer
            ->expects(self::once())
            ->method('renderHtmlToPdf')
            ->willThrowException(new \RuntimeException('Gotenberg unavailable'));
        $labelRenderer = new OrderLabelRenderer(
            self::getContainer()->get(Environment::class),
            $pdfRenderer,
            $this->createStub(FilesystemOperator::class),
            self::getContainer()->get(BakeryClock::class),
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(\Throwable::class);
        try {
            (new ProcessPrintJobHandler(
                $this->entityManager,
                $this->printJobRepository,
                $labelRenderer,
                self::getContainer()->get(BakeryClock::class),
                $this->createStub(PrinterClientInterface::class),
                new IppJobStateMapper(),
                $this->createStub(MessageBusInterface::class),
                $this->createStub(LoggerInterface::class),
            ))(new ProcessPrintJob($printJob->getId()));
        } finally {
            $this->entityManager->clear();
            $stored = $this->printJobRepository->find($printJob->getId());
            self::assertInstanceOf(PrintJob::class, $stored);
            self::assertSame(PrintJobStatus::FAILED, $stored->getStatus());
            self::assertSame(1, $stored->getAttemptCount());
            self::assertNotNull($stored->getErrorMessage());
            self::assertNull($stored->getDocumentPath());
        }
    }

    public function testCancelledSubmittedAndCompletedJobsAreIgnored(): void
    {
        $this->createDefaultPrinter();
        $order = $this->createOrder();
        $pdfRenderer = $this->createMock(DocumentRendererInterface::class);
        $pdfRenderer->expects(self::never())->method('renderHtmlToPdf');
        $labelRenderer = new OrderLabelRenderer(
            self::getContainer()->get(Environment::class),
            $pdfRenderer,
            $this->createStub(FilesystemOperator::class),
            self::getContainer()->get(BakeryClock::class),
            $this->createStub(LoggerInterface::class),
        );
        $handler = new ProcessPrintJobHandler(
            $this->entityManager,
            $this->printJobRepository,
            $labelRenderer,
            self::getContainer()->get(BakeryClock::class),
            $this->createStub(PrinterClientInterface::class),
            new IppJobStateMapper(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(LoggerInterface::class),
        );

        foreach ([PrintJobStatus::CANCELLED, PrintJobStatus::SUBMITTED, PrintJobStatus::COMPLETED] as $status) {
            $printJob = (new PrintJob())
                ->setPrinter($this->printerRepository->findConfiguredLabelDefaults()[0])
                ->setDocumentType(PrintDocumentType::LABEL)
                ->setStatus($status)
                ->setOrder($order);
            $this->entityManager->persist($printJob);
            $this->entityManager->flush();

            $handler(new ProcessPrintJob($printJob->getId()));

            self::assertSame($status, $printJob->getStatus());
            self::assertSame(0, $printJob->getAttemptCount());
        }
    }

    public function testSubmittedJobCompletionIsPersisted(): void
    {
        $printer = $this->createDefaultPrinter();
        $order = $this->createOrder();
        $printJob = (new PrintJob())
            ->setPrinter($printer)
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus(PrintJobStatus::SUBMITTED)
            ->setExternalJobId('42')
            ->setOrder($order);
        $this->entityManager->persist($printJob);
        $this->entityManager->flush();

        $printerClient = $this->createMock(PrinterClientInterface::class);
        $printerClient->expects(self::once())->method('getJobStatus')->with($printer, '42')->willReturn(
            new PrintJobStatusSnapshot(PrintJobState::COMPLETED, ['job-completed-successfully']),
        );
        $handler = new RefreshPrintJobStatusHandler(
            $this->printJobRepository,
            $this->entityManager,
            $printerClient,
            new IppJobStateMapper(),
            self::getContainer()->get(BakeryClock::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(LoggerInterface::class),
        );

        $handler(new RefreshPrintJobStatus($printJob->getId()));

        self::assertSame(PrintJobStatus::COMPLETED, $printJob->getStatus());
        self::assertNotNull($printJob->getCompletedAt());
    }

    public function testNonTerminalJobRemainsSubmittedAndSchedulesARefresh(): void
    {
        $printer = $this->createDefaultPrinter();
        $order = $this->createOrder();
        $printJob = (new PrintJob())
            ->setPrinter($printer)
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus(PrintJobStatus::SUBMITTED)
            ->setExternalJobId('42')
            ->setOrder($order);
        $this->entityManager->persist($printJob);
        $this->entityManager->flush();

        $printerClient = $this->createMock(PrinterClientInterface::class);
        $printerClient->expects(self::once())->method('getJobStatus')->willReturn(new PrintJobStatusSnapshot(PrintJobState::PROCESSING));
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $message): bool => $message instanceof RefreshPrintJobStatus && 2 === $message->checkNumber))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $handler = new RefreshPrintJobStatusHandler(
            $this->printJobRepository,
            $this->entityManager,
            $printerClient,
            new IppJobStateMapper(),
            self::getContainer()->get(BakeryClock::class),
            $messageBus,
            $this->createStub(LoggerInterface::class),
        );

        $handler(new RefreshPrintJobStatus($printJob->getId(), 1));

        self::assertSame(PrintJobStatus::SUBMITTED, $printJob->getStatus());
    }

    public function testAmbiguousSubmissionFailsWithoutMakingASecondAttemptPossible(): void
    {
        $printer = $this->createDefaultPrinter();
        $order = $this->createOrder();
        $printJob = (new PrintJob())
            ->setPrinter($printer)
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus(PrintJobStatus::RENDERED)
            ->setDocumentPath('labels/2026/10/order-1234.pdf')
            ->setOrder($order);
        $this->entityManager->persist($printJob);
        $this->entityManager->flush();
        $printerClient = $this->createMock(PrinterClientInterface::class);
        $printerClient->expects(self::once())->method('submitPdf')->willThrowException(
            PrinterSubmissionException::outcomeUnknown(new \RuntimeException('connection closed')),
        );
        $labelRenderer = new OrderLabelRenderer(
            self::getContainer()->get(Environment::class),
            $this->createStub(DocumentRendererInterface::class),
            $this->createStub(FilesystemOperator::class),
            self::getContainer()->get(BakeryClock::class),
            $this->createStub(LoggerInterface::class),
        );
        $handler = new ProcessPrintJobHandler(
            $this->entityManager,
            $this->printJobRepository,
            $labelRenderer,
            self::getContainer()->get(BakeryClock::class),
            $printerClient,
            new IppJobStateMapper(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(LoggerInterface::class),
        );

        $handler(new ProcessPrintJob($printJob->getId()));

        self::assertSame(PrintJobStatus::FAILED, $printJob->getStatus());
        self::assertStringContainsString('unknown', strtolower((string) $printJob->getErrorMessage()));
        self::assertSame('labels/2026/10/order-1234.pdf', $printJob->getDocumentPath());
    }

    private function createDefaultPrinter(): Printer
    {
        $printer = (new Printer())
            ->setName('Configured label printer')
            ->setAddress('ipp://printer.example/labels')
            ->setForLabels(true)
            ->setDefaultForLabels(true);
        $this->entityManager->persist($printer);
        $this->entityManager->flush();

        return $printer;
    }

    private function createOrder(): Order
    {
        $phone = PhoneNumberUtil::getInstance()->parse('+18125551234', 'US');
        $customer = (new Customer())->setName('Snapshot Customer')->setPhone($phone);
        $user = User::new(email: 'ce@example.com', name: 'Counter Employee', employee: true)->setPlainPassword('test-password');
        $productType = (new ProductType())->setName('Donuts');
        $unit = (new Unit())->setName('Dozen')->setPackageUnit(true);
        $this->entityManager->persist($customer);
        $this->entityManager->persist($user);
        $this->entityManager->persist($productType);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();

        $order = (new Order())
            ->setOrderNumber('1234')
            ->setCustomer($customer)
            ->setCustomerName('Snapshot Customer')
            ->setCustomerPhone($phone)
            ->setUser($user)
            ->setPickupAt(new \DateTimeImmutable('2026-10-10 09:30:00'))
            ->setOrderedAt(new \DateTimeImmutable('2026-10-08 12:00:00'));
        $order->addItem(
            (new OrderItem())
                ->setProductType($productType)
                ->setQuantity('2')
                ->setUnit($unit)
                ->setDescription('Glazed donuts'),
        );
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
