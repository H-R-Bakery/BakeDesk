<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Document\ConfigurableDocumentRendererInterface;
use App\Application\Order\BakeryClock;
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
use App\Model\OrderStatus;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ProductionReportControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    private BakeryClock $bakeryClock;

    private User $user;

    private ProductType $donuts;

    private ProductType $cookies;

    private Unit $each;

    private Unit $dozen;

    private Unit $tray;

    public function testMixedUnitsCompletedOrdersAndSnapshotsAreRenderedByProductType(): void
    {
        $date = new \DateTimeImmutable('2026-10-09', new \DateTimeZone($this->bakeryClock->getTimezoneName()));
        $customer = $this->createCustomer('Snapshot Customer', '+18125550001');
        $this->createOrder('1001', $date->setTime(8, 0), $customer, [
            [$this->donuts, '1', $this->dozen, 'Glazed'],
            [$this->cookies, '2', $this->each, 'Chocolate Chip'],
        ]);
        $this->createOrder('1004', $date->setTime(9, 30), $this->createCustomer('Completed Customer', '+18125550002'), [
            [$this->donuts, '14', $this->each, 'Assorted'],
            [$this->cookies, '1', $this->tray, 'Sugar'],
        ], OrderStatus::COMPLETED);
        $customer->setName('Changed Current Customer');
        $this->entityManager->flush();
        $this->createOrder('1005', $date->setTime(10, 0), $this->createCustomer('Cancelled Customer', '+18125550003'), [
            [$this->donuts, '100', $this->each, 'Cancelled'],
        ], OrderStatus::CANCELLED);

        $this->client->request('GET', '/reports/production?date=2026-10-09');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Production Report');
        self::assertStringContainsString('Friday, October 9, 2026', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Total to make: 26 Each', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Total to make: 22 Each', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('1 Dozen', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('12 Each', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('14 Each', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Snapshot Customer', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Changed Current Customer', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Cancelled Customer', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('data-toggle="table"', (string) $this->client->getResponse()->getContent());
    }

    public function testCurrentUnitConversionIsUsedWithoutDozenNameLogic(): void
    {
        $this->dozen->setEachEquivalent('13');
        $this->entityManager->flush();
        $date = new \DateTimeImmutable('2026-10-09', new \DateTimeZone($this->bakeryClock->getTimezoneName()));
        $this->createOrder('2001', $date->setTime(8, 0), $this->createCustomer('Config Customer', '+18125550004'), [
            [$this->donuts, '1', $this->dozen, 'Configured'],
            [$this->donuts, '14', $this->each, 'Each'],
        ]);

        $this->client->request('GET', '/reports/production?date=2026-10-09');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Total to make: 27 Each', (string) $this->client->getResponse()->getContent());
    }

    public function testPickupDateUsesBakeryTimezoneBoundaries(): void
    {
        $timezone = new \DateTimeZone($this->bakeryClock->getTimezoneName());
        $selectedDate = new \DateTimeImmutable('2026-10-09', $timezone);
        $this->createOrder('3001', $selectedDate->setTime(23, 59), $this->createCustomer('Selected Date', '+18125550005'), [
            [$this->donuts, '1', $this->each, 'Selected'],
        ]);
        $this->createOrder('3000', $selectedDate->modify('-1 day')->setTime(23, 59), $this->createCustomer('Previous Date', '+18125550006'), [
            [$this->donuts, '2', $this->each, 'Previous'],
        ]);
        $this->createOrder('3002', $selectedDate->modify('+1 day')->setTime(0, 0), $this->createCustomer('Following Date', '+18125550007'), [
            [$this->donuts, '3', $this->each, 'Following'],
        ]);

        $this->client->request('GET', '/reports/production?date=2026-10-09');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Selected Date', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Previous Date', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Following Date', (string) $this->client->getResponse()->getContent());
    }

    public function testPackagingRulesDoNotChangeProductionContribution(): void
    {
        $this->entityManager->persist((new PackagingRule())
            ->setProductType($this->cookies)
            ->setUnit($this->each)
            ->setQuantityPerPackage('24'));
        $this->entityManager->flush();
        $date = new \DateTimeImmutable('2026-10-09', new \DateTimeZone($this->bakeryClock->getTimezoneName()));
        $this->createOrder('4001', $date->setTime(8, 0), $this->createCustomer('Package Customer', '+18125550008'), [
            [$this->cookies, '30', $this->each, 'Chocolate'],
        ]);

        $this->client->request('GET', '/reports/production?date=2026-10-09');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Total to make: 30 Each', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('24 + 6', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->entityManager->getRepository(PrintJob::class)->count([]));
    }

    public function testEmptyAndInvalidDatesHaveUsefulResponses(): void
    {
        $this->client->request('GET', '/reports/production?date=2026-10-11');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No production items found for this pickup date.', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('.alert-secondary', 'No report printers are configured.');
        self::assertSelectorNotExists('select[name="printer"]');

        $this->client->request('GET', '/reports/production?date=not-a-date');

        self::assertResponseStatusCodeSame(400);
    }

    public function testPdfRouteReturnsStoredReportPdfWithoutCreatingPrintJobs(): void
    {
        $date = new \DateTimeImmutable('2026-10-09', new \DateTimeZone($this->bakeryClock->getTimezoneName()));
        $this->createOrder('5001', $date->setTime(8, 0), $this->createCustomer('PDF Customer', '+18125550009'), [
            [$this->donuts, '1', $this->dozen, 'Glazed'],
        ]);
        $pdfRenderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $pdfRenderer
            ->expects(self::once())
            ->method('renderHtmlToPdfWithOptions')
            ->with(
                self::callback(static fn (string $html): bool => str_contains($html, 'TOTAL TO MAKE: 12 EACH')),
                '8.5in',
                '11in',
                'production-2026-10-09',
            )
            ->willReturn('%PDF-1.7 test report');
        self::getContainer()->set(ConfigurableDocumentRendererInterface::class, $pdfRenderer);

        $this->client->request('GET', '/reports/production/pdf?date=2026-10-09');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('inline;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('%PDF-1.7 test report', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->entityManager->getRepository(PrintJob::class)->count([]));
    }

    public function testOnlyActiveReportPrintersAppearInThePrintForm(): void
    {
        $this->createPrinter('Kitchen report printer', true, true);
        $this->createPrinter('Inactive report printer', false, true);
        $this->createPrinter('Label-only printer', true, false);

        $this->client->request('GET', '/reports/production?date=2026-10-09');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Kitchen report printer', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Inactive report printer', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Label-only printer', (string) $this->client->getResponse()->getContent());
    }

    public function testValidPrintRequestQueuesReportJobAndRedirectsToSelectedDate(): void
    {
        $printer = $this->createPrinter('Kitchen report printer', true, true);
        $transport = self::getContainer()->get('messenger.transport.print');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $token = $this->printFormToken();
        $this->client->request('POST', '/reports/production/print', [
            '_token' => $token,
            'date' => '2026-10-09',
            'printer' => (string) $printer->getId(),
        ]);

        self::assertResponseRedirects('/reports/production?date=2026-10-09');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(ProcessPrintJob::class, $sent[0]->getMessage());
        $this->client->followRedirect();
        self::assertStringContainsString('Production report for October 9, 2026 queued for Kitchen report printer.', (string) $this->client->getResponse()->getContent());
        $printJob = $this->entityManager->getRepository(PrintJob::class)->findOneBy([]);
        self::assertInstanceOf(PrintJob::class, $printJob);
        self::assertSame($printJob->getId(), $sent[0]->getMessage()->printJobId);
        self::assertSame(PrintDocumentType::REPORT, $printJob->getDocumentType());
        self::assertSame(PrintJobStatus::QUEUED, $printJob->getStatus());
        self::assertSame('2026-10-09', $printJob->getReportDate()?->format('Y-m-d'));
        self::assertSame($printer->getId(), $printJob->getPrinter()?->getId());
        self::assertNull($printJob->getOrder());
        self::assertNull($printJob->getOrderItem());
        self::assertNull($printJob->getPackageNumber());
        self::assertNull($printJob->getPackageCount());
        self::assertNull($printJob->getPackageQuantity());
        self::assertNull($printJob->getDocumentPath());
    }

    public function testPrintRequestRequiresCsrfAndRejectsUnavailablePrinters(): void
    {
        $inactive = $this->createPrinter('Inactive report printer', false, true);
        $labelOnly = $this->createPrinter('Label-only printer', true, false);
        $this->createPrinter('Valid report printer', true, true);
        $token = $this->printFormToken();

        foreach ([['bad-token', $inactive], [$token, $labelOnly]] as [$csrfToken, $printer]) {
            $this->client->request('POST', '/reports/production/print', [
                '_token' => $csrfToken,
                'date' => '2026-10-09',
                'printer' => (string) $printer->getId(),
            ]);

            if ('bad-token' === $csrfToken) {
                self::assertResponseStatusCodeSame(403);
            } else {
                self::assertResponseRedirects('/reports/production?date=2026-10-09');
            }
        }

        $this->client->request('POST', '/reports/production/print', [
            '_token' => $token,
            'date' => '2026-10-09',
            'printer' => '999999',
        ]);

        self::assertResponseRedirects('/reports/production?date=2026-10-09');
        self::assertSame(0, $this->entityManager->getRepository(PrintJob::class)->count([]));
    }

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $_ENV['BAKERY_TIMEZONE'] = 'America/Indiana/Indianapolis';
        $_SERVER['BAKERY_TIMEZONE'] = 'America/Indiana/Indianapolis';

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->bakeryClock = self::getContainer()->get(BakeryClock::class);

        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->entityManager->getConnection()->executeStatement('DROP INDEX uniq_printer_default_for_labels');
        }

        $this->user = User::new(email: 'report@example.com', name: 'Report Employee', employee: true)->setPlainPassword('test-password');
        $this->donuts = (new ProductType())->setName('Donuts')->setSortOrder(10);
        $this->cookies = (new ProductType())->setName('Cookies')->setSortOrder(20);
        $this->each = (new Unit())->setName('Each')->setEachEquivalent('1')->setPackageUnit(false);
        $this->dozen = (new Unit())->setName('Dozen')->setEachEquivalent('12')->setPackageUnit(false);
        $this->tray = (new Unit())->setName('Tray')->setEachEquivalent('20')->setPackageUnit(false);
        foreach ([$this->user, $this->donuts, $this->cookies, $this->each, $this->dozen, $this->tray] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<array{0: ProductType, 1: string, 2: Unit, 3: string}> $items
     */
    private function createOrder(string $number, \DateTimeImmutable $pickupAt, Customer $customer, array $items, OrderStatus $status = OrderStatus::OPEN): Order
    {
        $order = (new Order())
            ->setOrderNumber($number)
            ->setCustomer($customer)
            ->setUser($this->user)
            ->setPickupAt($pickupAt)
            ->setOrderedAt($this->bakeryClock->now())
            ->setStatus($status);
        foreach ($items as $sortOrder => [$productType, $quantity, $unit, $description]) {
            $order->addItem((new OrderItem())
                ->setProductType($productType)
                ->setQuantity($quantity)
                ->setUnit($unit)
                ->setDescription($description)
                ->setSortOrder($sortOrder));
        }
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

    private function createPrinter(string $name, bool $active, bool $forReports): Printer
    {
        $printer = (new Printer())
            ->setName($name)
            ->setAddress('ipp://printer.example/'.$name)
            ->setActive($active)
            ->setForReports($forReports);
        $this->entityManager->persist($printer);
        $this->entityManager->flush();

        return $printer;
    }

    private function printFormToken(): string
    {
        $this->client->request('GET', '/reports/production?date=2026-10-09');

        return (string) $this->client->getCrawler()->filter('input[name="_token"]')->attr('value');
    }
}
