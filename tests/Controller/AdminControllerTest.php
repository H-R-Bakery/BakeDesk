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
use App\Model\PrintDocumentType;
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
        $this->admin = (new User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN']);
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

        $printer = (new Printer())->setName('History printer')->setAddress('ipp://printer.example/print');
        $printJob = (new PrintJob())->setPrinter($printer)->setDocumentType(PrintDocumentType::LABEL);
        $this->entityManager->persist($printer);
        $this->entityManager->persist($printJob);
        $this->entityManager->flush();

        $this->client->request('GET', '/admin/print-job/'.$printJob->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAllAdministrationCrudPagesRender(): void
    {
        $this->client->loginUser($this->admin);

        foreach (['customer', 'user', 'product-type', 'unit', 'packaging-rule', 'printer'] as $resource) {
            $this->client->request('GET', '/admin/'.$resource);
            self::assertResponseIsSuccessful($resource);
            $this->client->request('GET', '/admin/'.$resource.'/new');
            self::assertResponseIsSuccessful($resource.' new');
        }
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
