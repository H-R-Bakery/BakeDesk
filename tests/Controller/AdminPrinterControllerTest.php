<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Document\ConfigurableDocumentRendererInterface;
use App\Application\Printing\PrinterClientInterface;
use App\Application\Printing\PrinterState;
use App\Application\Printing\PrinterStatus;
use App\Application\Printing\PrinterStatusException;
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
use App\Model\PrintDocumentType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminPrinterControllerTest extends WebTestCase
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
        $this->entityManager->persist($this->admin);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testStatusDiagnosticsDisplayPrinterAndIppInformation(): void
    {
        $printer = $this->printer('Front Label Printer', true, true, false);
        $ippClient = $this->createMock(PrinterClientInterface::class);
        $ippClient->expects(self::once())->method('getPrinterStatus')->with($printer)->willReturn(new PrinterStatus(
            PrinterState::IDLE,
            true,
            documentFormatsSupported: ['application/pdf'],
            rawAttributes: ['printer-state' => '3'],
        ));
        self::getContainer()->set(PrinterClientInterface::class, $ippClient);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/printer/'.$printer->getId().'/diagnostics');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Front Label Printer');
        self::assertSelectorTextContains('body', 'ipp://printer.example/ipp/print');
        self::assertSelectorTextContains('body', 'Idle');
        self::assertSelectorTextContains('body', 'Accepting jobs');
        self::assertSelectorTextContains('body', 'Yes');
        self::assertSelectorTextContains('body', 'Supports application/pdf');
        self::assertSelectorTextContains('body', 'None reported');
    }

    public function testStoppedDiagnosticsDisplayReasonsAndDangerStyling(): void
    {
        $printer = $this->printer('Stopped Printer', true, true, false);
        $ippClient = $this->createStub(PrinterClientInterface::class);
        $ippClient->method('getPrinterStatus')->willReturn(new PrinterStatus(
            PrinterState::STOPPED,
            false,
            reasons: ['media-empty'],
            documentFormatsSupported: ['application/pdf'],
        ));
        self::getContainer()->set(PrinterClientInterface::class, $ippClient);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/printer/'.$printer->getId().'/diagnostics');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Stopped');
        self::assertSelectorTextContains('body', 'No');
        self::assertSelectorTextContains('body', 'media-empty');
        self::assertSelectorExists('.badge.text-bg-danger');
    }

    public function testStatusFailureIsShownWithoutChangingPrinterConfiguration(): void
    {
        $printer = $this->printer('Unavailable Printer', true, true, false);
        $ippClient = $this->createStub(PrinterClientInterface::class);
        $ippClient->method('getPrinterStatus')->willThrowException(PrinterStatusException::unavailable(new \RuntimeException('connection details')));
        self::getContainer()->set(PrinterClientInterface::class, $ippClient);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/printer/'.$printer->getId().'/diagnostics');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Unable to contact printer "Unavailable Printer".');
        self::assertStringNotContainsString('connection details', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->entityManager->getRepository(Printer::class)->find($printer->getId())?->isActive());
    }

    public function testLabelTestSubmitsPdfWithoutCreatingOperationalHistory(): void
    {
        $printer = $this->printer('Label Test Printer', true, true, false);
        $this->configureTestDocument(PrintDocumentType::LABEL);
        $ippClient = $this->createMock(PrinterClientInterface::class);
        $ippClient->expects(self::once())
            ->method('submitPdf')
            ->with(self::callback(static fn (Printer $candidate): bool => $candidate->getId() === $printer->getId()), self::matchesRegularExpression('#^diagnostics/printers/'.$printer->getId().'/label-test-.*\.pdf$#'), 'BakeDesk Label Printer Test', PrintDocumentType::LABEL)
            ->willReturn(new PrintSubmission('123', new PrintJobStatusSnapshot(PrintJobState::PENDING)));
        self::getContainer()->set(PrinterClientInterface::class, $ippClient);

        $this->postTest($printer, PrintDocumentType::LABEL);

        self::assertResponseRedirects('/admin/printer/'.$printer->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Test label submitted to "Label Test Printer".');
        self::assertSelectorTextContains('body', 'IPP job ID: 123');
        self::assertSelectorTextContains('body', 'Initial IPP status: Pending');
        self::assertSame(0, $this->entityManager->getRepository(Order::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(PrintJob::class)->count([]));
    }

    public function testReportTestSubmitsReportPdfWithoutCreatingOperationalHistory(): void
    {
        $printer = $this->printer('Report Test Printer', true, false, true);
        $this->configureTestDocument(PrintDocumentType::REPORT);
        $ippClient = $this->createMock(PrinterClientInterface::class);
        $ippClient->expects(self::once())
            ->method('submitPdf')
            ->with(self::callback(static fn (Printer $candidate): bool => $candidate->getId() === $printer->getId()), self::matchesRegularExpression('#^diagnostics/printers/'.$printer->getId().'/report-test-.*\.pdf$#'), 'BakeDesk Report Printer Test', PrintDocumentType::REPORT)
            ->willReturn(new PrintSubmission('124'));
        self::getContainer()->set(PrinterClientInterface::class, $ippClient);

        $this->postTest($printer, PrintDocumentType::REPORT);

        self::assertResponseRedirects('/admin/printer/'.$printer->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Test report submitted to "Report Test Printer".');
        self::assertSelectorTextContains('body', 'IPP job ID: 124');
        self::assertSame(0, $this->entityManager->getRepository(Order::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(PrintJob::class)->count([]));
    }

    public function testInvalidAndMissingCsrfPreventTestSubmission(): void
    {
        $printer = $this->printer('CSRF Printer', true, true, false);
        $renderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $renderer->expects(self::never())->method('renderHtmlToPdfWithOptions');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('write');
        $ippClient = $this->createMock(PrinterClientInterface::class);
        $ippClient->expects(self::never())->method('submitPdf');
        self::getContainer()->set(ConfigurableDocumentRendererInterface::class, $renderer);
        self::getContainer()->set('documents.storage', $storage);
        self::getContainer()->set(PrinterClientInterface::class, $ippClient);
        $this->client->loginUser($this->admin);

        $url = '/admin/printer/'.$printer->getId().'/test-label';
        $this->client->request('POST', $url);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    private function printer(string $name, bool $active, bool $forLabels, bool $forReports): Printer
    {
        $printer = (new Printer())
            ->setName($name)
            ->setAddress('ipp://printer.example/ipp/print')
            ->setActive($active)
            ->setForLabels($forLabels)
            ->setForReports($forReports);
        $this->entityManager->persist($printer);
        $this->entityManager->flush();

        return $printer;
    }

    private function configureTestDocument(PrintDocumentType $documentType): void
    {
        $renderer = $this->createMock(ConfigurableDocumentRendererInterface::class);
        $renderer
            ->expects(self::once())
            ->method('renderHtmlToPdfWithOptions')
            ->with(
                self::callback(static fn (mixed $value): bool => is_string($value)),
                PrintDocumentType::LABEL === $documentType ? '4in' : '8.5in',
                PrintDocumentType::LABEL === $documentType ? '6in' : '11in',
                self::callback(static fn (mixed $value): bool => is_string($value)),
            )
            ->willReturn('%PDF-1.7 test');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage
            ->expects(self::once())
            ->method('write')
            ->with(self::callback(static fn (mixed $value): bool => is_string($value)), '%PDF-1.7 test');
        self::getContainer()->set(ConfigurableDocumentRendererInterface::class, $renderer);
        self::getContainer()->set('documents.storage', $storage);
    }

    private function postTest(Printer $printer, PrintDocumentType $documentType): void
    {
        self::assertNotNull($printer->getId());
        $route = PrintDocumentType::LABEL === $documentType ? 'test-label' : 'test-report';
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/printer');
        $token = $crawler
            ->filter('form[action$="/admin/printer/'.$printer->getId().'/'.$route.'"] input[name="_token"]')
            ->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/admin/printer/'.$printer->getId().'/'.$route, ['_token' => $token]);
    }
}
