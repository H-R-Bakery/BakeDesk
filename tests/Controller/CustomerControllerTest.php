<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Order\OrderNumberGenerator;
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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    private KernelBrowser $client;

    private User $user;

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

        $metadata = array_map(
            $this->entityManager->getClassMetadata(...),
            [Customer::class, User::class, ProductType::class, Unit::class, Order::class, OrderItem::class, PackagingRule::class, Printer::class, PrintJob::class],
        );
        (new SchemaTool($this->entityManager))->createSchema($metadata);

        $this->user = User::new(email: 'history@example.com', name: 'Alex Baker', employee: true)->setPlainPassword('test-password');
        $this->productType = (new ProductType())->setName('Donuts')->setActive(true);
        $this->unit = (new Unit())->setName('Dozen')->setActive(true)->setPackageUnit(true);
        $this->entityManager->persist($this->user);
        $this->entityManager->persist($this->productType);
        $this->entityManager->persist($this->unit);
        $this->entityManager->flush();

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('1');
        self::getContainer()->set(OrderNumberGenerator::class, new OrderNumberGenerator($connection));
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testCustomerHistoryShowsCurrentDetailsAllStatusesSnapshotsAndItems(): void
    {
        $customer = $this->createCustomer('Jane Smith', '+18125550123');
        $open = $this->createOrder('1001', $customer, new \DateTimeImmutable('2026-10-10 14:00:00', new \DateTimeZone('UTC')), OrderStatus::OPEN, false, 'Glazed');
        $completed = $this->createOrder('1002', $customer, new \DateTimeImmutable('2026-10-11 14:00:00', new \DateTimeZone('UTC')), OrderStatus::COMPLETED, true, 'Chocolate');
        $cancelled = $this->createOrder('1003', $customer, new \DateTimeImmutable('2026-10-12 14:00:00', new \DateTimeZone('UTC')), OrderStatus::CANCELLED, false, 'Sprinkles');

        $customer->setName('Jane Current Smith');
        $this->entityManager->flush();

        $this->client->request('GET', '/customers/'.$customer->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Jane Current Smith');
        self::assertSelectorTextContains('body', '(812) 555-0123');
        self::assertSelectorTextContains('body', 'Active');
        self::assertSelectorTextContains('body', '1001');
        self::assertSelectorTextContains('body', '1002');
        self::assertSelectorTextContains('body', '1003');
        self::assertSelectorTextContains('tbody', 'Paid');
        self::assertSelectorTextContains('tbody', 'Not Paid');
        self::assertSelectorTextContains('tbody', 'Open');
        self::assertSelectorTextContains('tbody', 'Completed');
        self::assertSelectorTextContains('tbody', 'Cancelled');
        self::assertSelectorTextContains('tbody', '2 Dozen Donuts — Glazed');
        self::assertSelectorTextContains('tbody', 'Jane Smith');
        self::assertSelectorExists('a[href="/orders/'.$open->getId().'"]');
        self::assertSelectorExists('a[href="/orders/'.$completed->getId().'"]');
        self::assertSelectorExists('a[href="/orders/'.$cancelled->getId().'"]');
        self::assertSelectorExists('a[href="/customers"]');

        $rows = $this->client->getCrawler()->filter('tbody tr');
        self::assertStringContainsString('1003', $rows->eq(0)->text());
        self::assertStringContainsString('1002', $rows->eq(1)->text());
        self::assertStringContainsString('1001', $rows->eq(2)->text());
    }

    public function testCustomerDirectoryListsActiveAndInactiveCustomersWithHistoryLinks(): void
    {
        $active = $this->createCustomer('Jane Directory', '+18125550131');
        $inactive = $this->createCustomer('John Directory', '+18125550132', false);

        $this->client->request('GET', '/customers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Customers');
        self::assertSelectorTextContains('body', 'Find a customer and view their order history.');
        self::assertSelectorTextContains('#customers-table', 'Jane Directory');
        self::assertSelectorTextContains('#customers-table', 'John Directory');
        self::assertSelectorTextContains('#customers-table', '(812) 555-0131');
        self::assertSelectorTextContains('#customers-table', '(812) 555-0132');
        self::assertSelectorTextContains('#customers-table', 'Active');
        self::assertSelectorTextContains('#customers-table', 'Inactive');
        self::assertSelectorExists('a[href="/customers/'.$active->getId().'"]');
        self::assertSelectorExists('a[href="/customers/'.$inactive->getId().'"]');
        self::assertSelectorExists('a[href="/order/new?customer='.$active->getId().'"]');
        self::assertSelectorNotExists('a[href="/order/new?customer='.$inactive->getId().'"]');
    }

    public function testCustomerDirectorySearchesAllCustomersByNameAndPhone(): void
    {
        $nameMatch = $this->createCustomer('Directory Search Match', '+18125550133', false);
        $phoneMatch = $this->createCustomer('Another Directory Customer', '+18125550134');
        $this->createCustomer('Unrelated Directory Customer', '+18125550135');

        $this->client->request('GET', '/customers?q=search%20match');
        self::assertSelectorTextContains('#customers-table', 'Directory Search Match');
        self::assertStringNotContainsString('Another Directory Customer', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Unrelated Directory Customer', (string) $this->client->getResponse()->getContent());
        self::assertSelectorExists('a[href="/customers/'.$nameMatch->getId().'"]');

        $this->client->request('GET', '/customers?q=812-555-0134');
        self::assertSelectorTextContains('#customers-table', 'Another Directory Customer');
        self::assertStringNotContainsString('Directory Search Match', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Unrelated Directory Customer', (string) $this->client->getResponse()->getContent());
        self::assertSelectorExists('a[href="/order/new?customer='.$phoneMatch->getId().'"]');
    }

    public function testCustomerDirectoryPaginatesDeterministicallyAndRetainsSearch(): void
    {
        for ($number = 26; $number >= 1; --$number) {
            $this->createCustomer(sprintf('Paging Directory %02d', $number), sprintf('+18125550%03d', $number));
        }

        $this->client->request('GET', '/customers?q=Paging%20Directory&page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('#customers-table tbody tr'));
        self::assertSelectorTextContains('#customers-table', 'Paging Directory 26');
        $paginationLinks = $this->client->getCrawler()->filter('a.page-link')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertTrue([] !== array_filter($paginationLinks, static fn (string $href): bool => str_contains($href, 'q=Paging') && str_contains($href, 'page=1')));

        $this->client->request('GET', '/customers?q=Paging%20Directory&page=1');
        self::assertCount(25, $this->client->getCrawler()->filter('#customers-table tbody tr'));
        self::assertSelectorTextContains('#customers-table tbody tr:first-child', 'Paging Directory 01');
        self::assertSelectorTextContains('#customers-table tbody tr:last-child', 'Paging Directory 25');
        self::assertStringNotContainsString('Paging Directory 26', (string) $this->client->getResponse()->getContent());
    }

    public function testCustomerAutocompleteRemainsActiveOnly(): void
    {
        $active = $this->createCustomer('Autocomplete Match', '+18125550136');
        $this->createCustomer('Autocomplete Match Inactive', '+18125550137', false);

        $this->client->request('GET', '/customer/autocomplete?q=Autocomplete');

        self::assertResponseIsSuccessful();
        $results = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $results);
        self::assertSame($active->getId(), $results[0]['id']);
    }

    public function testCustomerHistoryPaginatesInStablePickupOrder(): void
    {
        $customer = $this->createCustomer('Paging Customer', '+18125550124');
        for ($number = 1; $number <= 26; ++$number) {
            $this->createOrder(
                sprintf('P%02d', $number),
                $customer,
                new \DateTimeImmutable('2026-10-20 14:00:00', new \DateTimeZone('UTC')),
                description: sprintf('Item %02d', $number),
            );
        }

        $this->client->request('GET', '/customers/'.$customer->getId());
        $firstPageNumbers = $this->client->getCrawler()->filter('tbody tr')->each(static fn ($row): string => trim($row->filter('td a')->first()->text()));
        self::assertCount(25, $firstPageNumbers);
        self::assertSame('P26', $firstPageNumbers[0]);
        self::assertNotContains('P01', $firstPageNumbers);
        self::assertSelectorExists('a[href="/customers/'.$customer->getId().'?page=2"]');

        $this->client->request('GET', '/customers/'.$customer->getId().'?page=2');
        $secondPageNumbers = $this->client->getCrawler()->filter('tbody tr')->each(static fn ($row): string => trim($row->filter('td a')->first()->text()));
        self::assertSame(['P01'], $secondPageNumbers);
        self::assertSame([], array_intersect($firstPageNumbers, $secondPageNumbers));
    }

    public function testOrderPagesLinkCustomerNamesToOperationalHistory(): void
    {
        $customer = $this->createCustomer('Linked Customer', '+18125550125');
        $order = $this->createOrder('2001', $customer, new \DateTimeImmutable('2026-10-20 14:00:00', new \DateTimeZone('UTC')));

        $this->client->request('GET', '/orders');
        self::assertSelectorExists('a[href="/customers/'.$customer->getId().'"]');

        $this->client->request('GET', '/orders/'.$order->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/customers/'.$customer->getId().'"]');
    }

    public function testActiveCustomerPrefillsAndSubmitsThroughNormalOrderCreation(): void
    {
        $customer = $this->createCustomer('Prefilled Customer', '+18125550126');
        $printer = (new Printer())
            ->setName('History test printer')
            ->setAddress('ipp://printer.example/history')
            ->setForLabels(true)
            ->setDefaultForLabels(true);
        $this->entityManager->persist($printer);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/order/new?customer='.$customer->getId());

        self::assertResponseIsSuccessful();
        self::assertSame((string) $customer->getId(), $crawler->filter('input[name="new_order[customerId]"]')->attr('value'));
        self::assertSame('Prefilled Customer', $crawler->filter('input[name="new_order[customerName]"]')->attr('value'));
        self::assertStringContainsString('555-0126', (string) $crawler->filter('input[name="new_order[customerPhone]"]')->attr('value'));
        self::assertNotSame('', $crawler->filter('input[name="new_order[pickupDate]"]')->attr('value'));
        self::assertNotSame('', $crawler->filter('input[name="new_order[pickupTime]"]')->attr('value'));
        self::assertSelectorExists('select[name="new_order[items][0][productType]"]');

        $form = $crawler->selectButton('Save order')->form([
            'new_order[customerName]' => 'Prefilled Snapshot Name',
            'new_order[customerPhone]' => '8125550126',
            'new_order[user]' => (string) $this->user->getId(),
            'new_order[pickupDate]' => '2026-10-21',
            'new_order[pickupTime]' => '09:30',
            'new_order[items][0][productType]' => (string) $this->productType->getId(),
            'new_order[items][0][quantity]' => '2',
            'new_order[items][0][unit]' => (string) $this->unit->getId(),
            'new_order[items][0][description]' => 'Glazed',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/order/1/created');
        $this->entityManager->clear();
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['orderNumber' => '1']);

        self::assertInstanceOf(Order::class, $order);
        self::assertSame($customer->getId(), $order->getCustomer()?->getId());
        self::assertSame('Prefilled Snapshot Name', $order->getCustomerName());
        self::assertSame(2, $this->entityManager->getRepository(PrintJob::class)->count([]));
        self::assertCount(2, self::getContainer()->get('messenger.transport.print')->getSent());
    }

    public function testInactiveAndUnknownCustomerPrefillAreSafe(): void
    {
        $inactive = $this->createCustomer('Inactive Customer', '+18125550127', false);

        $this->client->request('GET', '/customers/'.$inactive->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Inactive');
        self::assertSelectorTextContains('body', 'No orders found for this customer.');
        self::assertSelectorNotExists('a[href="/order/new?customer='.$inactive->getId().'"]');

        $crawler = $this->client->request('GET', '/order/new?customer='.$inactive->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="new_order[customerId]"][value="'.$inactive->getId().'"]');
        self::assertSelectorTextContains('.alert-warning', 'inactive');

        $crawler = $this->client->request('GET', '/order/new?customer=999999');
        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('input[name="new_order[customerId]"]')->attr('value'));
        self::assertSelectorTextContains('.alert-warning', 'could not be found');
        self::assertSelectorTextContains('h1', 'New order');
    }

    public function testUnknownCustomerHistoryReturnsNotFound(): void
    {
        $this->client->request('GET', '/customers/999999');

        self::assertResponseStatusCodeSame(404);
    }

    private function createCustomer(string $name, string $phone, bool $active = true): Customer
    {
        $customer = (new Customer())
            ->setName($name)
            ->setPhone(PhoneNumberUtil::getInstance()->parse($phone, 'US'))
            ->setActive($active);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function createOrder(
        string $number,
        Customer $customer,
        \DateTimeImmutable $pickupAt,
        OrderStatus $status = OrderStatus::OPEN,
        bool $paid = false,
        string $description = 'Glazed',
    ): Order {
        $order = (new Order())
            ->setOrderNumber($number)
            ->setCustomer($customer)
            ->setUser($this->user)
            ->setPickupAt($pickupAt)
            ->setOrderedAt($pickupAt->modify('-1 day'))
            ->setStatus($status)
            ->setPaid($paid);
        $order->addItem(
            (new OrderItem())
                ->setProductType($this->productType)
                ->setQuantity('2')
                ->setUnit($this->unit)
                ->setDescription($description)
                ->setSortOrder(0),
        );
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
