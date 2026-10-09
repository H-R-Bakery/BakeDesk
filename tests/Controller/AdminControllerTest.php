<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AdminControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    private KernelBrowser $client;

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
            [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $this->admin = User::new('admin@example.test', 'Admin', employee: false)->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->admin, 'correct horse battery staple'));
        $this->entityManager->persist($this->admin);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testAnonymousAdminAccessRedirectsToLoginAndNormalPagesRemainPublic(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');

        $this->client->request('GET', '/order/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin"]');
        self::assertSelectorTextContains('a[href="/admin"]', 'Admin');
        self::assertSelectorExists('img.navbar-brand-logo');
        self::assertStringContainsString('HRBakeryLogo', (string) $this->client->getResponse()->getContent());
    }

    public function testOperationalNavbarKeepsAdminInMoreDropdown(): void
    {
        $this->client->request('GET', '/order/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.navbar.navbar-expand');
        self::assertSelectorTextContains('nav .navbar-nav > .nav-item > a[href="/order/new"]', 'New Order');
        self::assertSelectorTextContains('nav .navbar-nav > .nav-item > a[href="/orders"]', 'Orders');
        self::assertSelectorTextContains('nav .navbar-nav > .dropdown > a', 'More');
        self::assertSelectorTextContains('nav .dropdown-menu', 'Customers');
        self::assertSelectorTextContains('nav .dropdown-menu', 'Production Report');
        self::assertSelectorTextContains('nav .dropdown-menu', 'Admin');
        self::assertSelectorExists('nav .dropdown-toggle[data-bs-toggle="dropdown"][aria-expanded="false"]');
        self::assertCount(2, $this->client->getCrawler()->filter('nav .navbar-nav > .nav-item:not(.dropdown) > a'));

        $this->client->request('GET', '/customers');
        self::assertSelectorExists('nav .dropdown-toggle.active');
        self::assertSelectorExists('nav .dropdown-item.active[href="/customers"]');
    }

    public function testAnonymousCannotAccessOrderAdmin(): void
    {
        $this->client->request('GET', '/admin/order');

        self::assertResponseRedirects('/admin/login');
    }

    public function testAdminSeesAdminLinkOnPublicPages(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/order/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin"]');
        self::assertSelectorTextContains('a[href="/admin"]', 'Admin');
    }

    public function testAdminCanLogInSeeDashboardAndLogOut(): void
    {
        $crawler = $this->client->request('GET', '/admin/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'admin@example.test',
            '_password' => 'correct horse battery staple',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'BakeDesk');
        self::assertSelectorTextContains('body', 'Customers');
        self::assertSelectorTextContains('body', 'Orders');
        self::assertSelectorTextContains('body', 'Print Jobs');

        $this->client->request('GET', '/admin/logout');
        self::assertResponseRedirects('/admin/login');
    }

    public function testAdminCrudUsesUsefulEntityLabelsAndPrintJobsAreReadOnly(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/customer');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Customers');
        self::assertStringNotContainsString('Delete', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/print-job');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Create new', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Edit', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Delete', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('data-action-name="batchDelete"', (string) $this->client->getResponse()->getContent());

        $printer = (new Printer())->setName('History printer')->setAddress('ipp://printer.example/print');
        $printJob = (new PrintJob())->setPrinter($printer)->setDocumentType(PrintDocumentType::LABEL);
        $this->entityManager->persist($printer);
        $this->entityManager->persist($printJob);
        $this->entityManager->flush();

        $this->client->request('GET', '/admin/print-job/'.$printJob->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testOrderCrudAllowsPartialEditingAndLinksToOperationalDetail(): void
    {
        $customer = (new Customer())
            ->setName('Order History Customer')
            ->setPhone(\libphonenumber\PhoneNumberUtil::getInstance()->parse('+18125550123', 'US'));
        $productType = (new ProductType())->setName('Donuts');
        $unit = (new Unit())->setName('Each');
        $order = (new Order())
            ->setOrderNumber('9001')
            ->setCustomer($customer)
            ->setUser($this->admin)
            ->setPickupAt(new \DateTimeImmutable('2026-10-10 09:00:00', new \DateTimeZone('UTC')))
            ->setOrderedAt(new \DateTimeImmutable('2026-10-09 12:00:00', new \DateTimeZone('UTC')))
            ->setStatus(OrderStatus::OPEN)
            ->setNotes('History note');
        $order->addItem((new OrderItem())
            ->setProductType($productType)
            ->setUnit($unit)
            ->setQuantity('6')
            ->setDescription('Glazed'));
        $this->entityManager->persist($customer);
        $this->entityManager->persist($productType);
        $this->entityManager->persist($unit);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/order');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '9001');
        self::assertSelectorTextContains('body', 'Order History Customer');
        self::assertStringContainsString('/orders/'.$order->getId(), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Create new', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Edit', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Delete', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/order/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/order/'.$order->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'History note');
        self::assertSelectorTextContains('body', 'Glazed');
        self::assertStringContainsString('Edit', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/order/'.$order->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="Order[paid]"]');
        self::assertSelectorExists('select[name="Order[status]"]');
        self::assertSelectorExists('textarea[name="Order[notes]"]');
        self::assertSelectorExists('input[name^="Order[pickupAt]"]');
        foreach (['customer', 'customerName', 'customerPhone', 'user', 'orderNumber', 'orderedAt', 'items'] as $field) {
            self::assertSelectorNotExists('[name^="Order['.$field.']"]');
        }
    }

    public function testPrintJobCanBeCancelledBeforeSubmissionAndCannotAfterSubmission(): void
    {
        $printer = (new Printer())->setName('Admin printer')->setAddress('ipp://printer.example/admin');
        $queued = (new PrintJob())
            ->setPrinter($printer)
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus(PrintJobStatus::QUEUED)
            ->setDocumentPath('labels/shared.pdf')
            ->setAttemptCount(2);
        $submitted = (new PrintJob())
            ->setPrinter($printer)
            ->setDocumentType(PrintDocumentType::LABEL)
            ->setStatus(PrintJobStatus::SUBMITTED);
        $this->entityManager->persist($printer);
        $this->entityManager->persist($queued);
        $this->entityManager->persist($submitted);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/print-job');
        $cancelToken = $crawler->filter('form[action$="/admin/print-job/'.$queued->getId().'/cancel"] input[name="_token"]')->attr('value');
        self::assertNotNull($cancelToken);
        $this->client->request('POST', '/admin/print-job/'.$queued->getId().'/cancel', [
            '_token' => $cancelToken,
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();
        $cancelled = $this->entityManager->getRepository(PrintJob::class)->find($queued->getId());
        self::assertInstanceOf(PrintJob::class, $cancelled);
        self::assertSame(PrintJobStatus::CANCELLED, $cancelled->getStatus());
        self::assertSame('labels/shared.pdf', $cancelled->getDocumentPath());
        self::assertSame(2, $cancelled->getAttemptCount());

        $this->client->request('POST', '/admin/print-job/'.$submitted->getId().'/cancel', [
            '_token' => $cancelToken,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(PrintJobStatus::SUBMITTED, $this->entityManager->getRepository(PrintJob::class)->find($submitted->getId())?->getStatus());
    }

    public function testTerminalPrintJobCanBeDeletedWithoutDeletingPrinter(): void
    {
        $printer = (new Printer())->setName('Retained printer')->setAddress('ipp://printer.example/retained');
        $printJob = (new PrintJob())
            ->setPrinter($printer)
            ->setDocumentType(PrintDocumentType::REPORT)
            ->setStatus(PrintJobStatus::FAILED)
            ->setDocumentPath('reports/shared.pdf');
        $this->entityManager->persist($printer);
        $this->entityManager->persist($printJob);
        $this->entityManager->flush();
        $printJobId = $printJob->getId();
        $printerId = $printer->getId();

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/print-job');
        $deleteToken = $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value');
        self::assertNotNull($deleteToken);
        $this->client->request('POST', '/admin/print-job/'.$printJobId.'/delete', [
            'token' => $deleteToken,
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->entityManager->getRepository(PrintJob::class)->find($printJobId));
        self::assertInstanceOf(Printer::class, $this->entityManager->getRepository(Printer::class)->find($printerId));
    }

    public function testAllAdministrationCrudPagesRender(): void
    {
        $this->client->loginUser($this->admin);

        foreach (['order', 'customer', 'user', 'product-type', 'unit', 'packaging-rule', 'printer'] as $resource) {
            $this->client->request('GET', '/admin/'.$resource);
            self::assertResponseIsSuccessful($resource);
            if ('order' !== $resource) {
                $this->client->request('GET', '/admin/'.$resource.'/new');
                self::assertResponseIsSuccessful($resource.' new');
            }
        }
    }

    public function testAdminUserCrudExposesAndPersistsOrderTakerAvailability(): void
    {
        $user = User::new('counter@example.test', 'Counter User', employee: false);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/user');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Available as order taker');
        self::assertSelectorTextContains('body', 'Counter User');

        $crawler = $this->client->request('GET', '/admin/user/'.$user->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Only active users enabled as order takers appear on the New Order form.');
        self::assertSelectorExists('input[name$="[employee]"]');

        $form = $crawler->selectButton('Save changes')->form();
        $values = $form->getPhpValues();
        $values['User']['employee'] = '1';
        $this->client->submit($form, $values);

        self::assertResponseRedirects();
        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $saved);
        self::assertTrue($saved->isEmployee());
    }

    public function testPackagingRuleUsingPackageUnitIsRejectedByAdminValidation(): void
    {
        $productType = (new ProductType())->setName('Cookies');
        $unit = (new Unit())->setName('Box')->setPackageUnit(true);
        $rule = (new PackagingRule())->setProductType($productType)->setUnit($unit)->setQuantityPerPackage('24');

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($rule);

        self::assertCount(1, $violations);
        self::assertSame('unit', $violations[0]->getPropertyPath());
        self::assertStringContainsString('non-package units', $violations[0]->getMessage());
    }

    public function testInvalidPrinterDefaultsAreRejectedByAdminValidation(): void
    {
        $invalid = (new Printer())->setName('Reports only')->setAddress('ipp://printer.example/print')->setActive(false);
        $reflection = new \ReflectionProperty(Printer::class, 'defaultForLabels');
        $reflection->setValue($invalid, true);
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($invalid);

        self::assertCount(2, $violations);
        self::assertStringContainsString('support labels', (string) $violations[0]->getMessage());
        self::assertStringContainsString('active', (string) $violations[1]->getMessage());
    }

    public function testPrinterFormReportsInvalidDefaultCombination(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/printer/new');
        $form = $crawler->selectButton('Create')->form([
            'Printer[name]' => 'Reports only',
            'Printer[address]' => 'ipp://printer.example/print',
            'Printer[defaultForLabels]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('must be active and available for labels', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->entityManager->getRepository(Printer::class)->count([]));
    }

    public function testHistoricalDataHasNoDestructiveCrudAction(): void
    {
        $customer = (new Customer())->setName('Historical Customer')->setPhone(\libphonenumber\PhoneNumberUtil::getInstance()->parse('+18125550123', 'US'));
        $this->entityManager->persist($customer);
        $this->entityManager->flush();
        $customerId = $customer->getId();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/customer/'.$customerId.'/delete');

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(Customer::class, $this->entityManager->getRepository(Customer::class)->find($customerId));
    }
}
