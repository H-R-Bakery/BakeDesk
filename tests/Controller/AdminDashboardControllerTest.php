<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Order\BakeryClock;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\PackagingRule;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Entity\User;
use App\Model\OrderStatus;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminDashboardControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    private BakeryClock $bakeryClock;

    private User $admin;

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
            [Customer::class, User::class, ProductType::class, Unit::class, Order::class, \App\Entity\OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $this->bakeryClock = self::getContainer()->get(BakeryClock::class);
        $this->admin = User::new('dashboard-admin@example.test', 'Dashboard Admin', employee: false)
            ->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->admin, 'password'));
        $this->entityManager->persist($this->admin);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testDashboardCountsUseOperationalStatusesAndBakeryLocalBoundaries(): void
    {
        $orderTaker = User::new('order-taker@example.test', 'Order Taker', employee: true);
        $this->entityManager->persist($orderTaker);

        $productType = (new ProductType())->setName('Donuts');
        $unit = (new Unit())->setName('Dozen')->setEachEquivalent('10.00');
        $printer = (new Printer())
            ->setName('Configured printer')
            ->setAddress('ipp://printer.example/print')
            ->setForLabels(true)
            ->setForReports(true)
            ->setDefaultForLabels(true);
        $this->entityManager->persist($productType);
        $this->entityManager->persist($unit);
        $this->entityManager->persist($printer);

        $today = $this->bakeryClock->today();
        $customer = $this->createCustomer();
        $this->createOrder('today-open', $customer, $today->setTime(0, 30), OrderStatus::OPEN, false);
        $this->createOrder('today-completed', $customer, $today->setTime(23, 30), OrderStatus::COMPLETED, false);
        $this->createOrder('today-cancelled', $customer, $today->setTime(12, 0), OrderStatus::CANCELLED, false);
        $this->createOrder('tomorrow-open', $customer, $today->modify('+1 day')->setTime(0, 30), OrderStatus::OPEN, true);
        $this->createOrder('past-open', $customer, $today->modify('-1 day')->setTime(12, 0), OrderStatus::OPEN, false);
        $this->entityManager->flush();

        foreach (PrintJobStatus::cases() as $status) {
            $this->entityManager->persist(
                (new PrintJob())
                    ->setPrinter($printer)
                    ->setDocumentType(PrintDocumentType::LABEL)
                    ->setStatus($status),
            );
        }
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#operations-heading + .row', "Today's Pickups");
        self::assertStringContainsString('>2<', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('>1<', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('body', 'Failed Print Jobs');
        self::assertSelectorTextContains('body', 'Needs attention.');
        self::assertSelectorTextContains('body', 'One active default printer is available for labels.');
        self::assertSelectorTextContains('body', '1 active report printer available.');
        self::assertSelectorTextContains('body', '1 active order taker available in New Order.');
        self::assertSelectorTextContains('body', '1 active product type configured.');
        self::assertSelectorTextContains('body', '1 active unit configured.');
        self::assertSelectorTextContains('body', 'Review Each equivalent values before first production use.');
        self::assertStringNotContainsString('non-positive Each equivalent', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('PackagingRule', (string) $this->client->getResponse()->getContent());
        self::assertSelectorExists('a[href="/orders?pickupDate='.$today->format('Y-m-d').'"]');
        self::assertSelectorExists('a[href="/orders"]');
        self::assertSelectorExists('a[href$="/admin/print-job"]');
        self::assertSelectorExists('a[href$="/admin/printer"]');
        self::assertStringNotContainsString('Check Status', (string) $this->client->getResponse()->getContent());
    }

    public function testDashboardReportsMissingReadinessConfigurationWithoutPrinterIo(): void
    {
        $printer = (new Printer())
            ->setName('Invalid default')
            ->setAddress('ipp://printer.example/print')
            ->setActive(false);
        $reflection = new \ReflectionProperty(Printer::class, 'defaultForLabels');
        $reflection->setValue($printer, true);
        $this->entityManager->persist($printer);
        $this->entityManager->flush();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No active default label printer is configured.');
        self::assertSelectorTextContains('body', 'No active report printer is configured. Reports remain available as PDFs.');
        self::assertSelectorTextContains('body', 'No active order takers are available in the New Order form.');
        self::assertSelectorTextContains('body', 'No active Product Types are configured.');
        self::assertSelectorTextContains('body', 'No active Units are configured.');
        self::assertStringNotContainsString('Unable to contact printer', (string) $this->client->getResponse()->getContent());
    }

    public function testReportPrinterIsOptionalAndPackagingRulesAreNotReadinessRequirements(): void
    {
        $printer = (new Printer())
            ->setName('Label only')
            ->setAddress('ipp://printer.example/print')
            ->setForLabels(true)
            ->setDefaultForLabels(true)
            ->setForReports(false);
        $this->entityManager->persist($printer);
        $this->entityManager->persist((new ProductType())->setName('Cookies'));
        $this->entityManager->persist((new Unit())->setName('Dozen')->setEachEquivalent('10.00'));
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No active report printer is configured. Reports remain available as PDFs.');
        self::assertStringNotContainsString('Packaging rules are missing', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Dozen must equal', (string) $this->client->getResponse()->getContent());
    }

    private function createCustomer(): Customer
    {
        $customer = (new Customer())
            ->setName('Dashboard Customer')
            ->setPhone(PhoneNumberUtil::getInstance()->parse('+18125550123', 'US'));
        $this->entityManager->persist($customer);

        return $customer;
    }

    private function createOrder(
        string $number,
        Customer $customer,
        \DateTimeImmutable $pickupAt,
        OrderStatus $status,
        bool $paid,
    ): void {
        $this->entityManager->persist(
            (new Order())
                ->setOrderNumber($number)
                ->setCustomer($customer)
                ->setUser($this->admin)
                ->setPickupAt($pickupAt)
                ->setOrderedAt($this->bakeryClock->now())
                ->setStatus($status)
                ->setPaid($paid),
        );
    }
}
