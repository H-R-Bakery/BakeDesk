<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Document\DocumentRendererInterface;
use App\Application\Document\OrderLabelRenderer;
use App\Application\Order\BakeryClock;
use App\Entity\Customer;
use App\Entity\Employee;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\ProductType;
use App\Entity\Unit;
use League\Flysystem\FilesystemOperator;
use libphonenumber\PhoneNumberUtil;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class OrderLabelRendererTest extends KernelTestCase
{
    public function testLabelContainsSnapshotAndOrderInformationAndStoresPdf(): void
    {
        self::bootKernel();

        $renderedHtml = '';
        $pdfRenderer = $this->createMock(DocumentRendererInterface::class);
        $pdfRenderer
            ->expects(self::once())
            ->method('renderHtmlToPdf')
            ->willReturnCallback(function (string $html) use (&$renderedHtml): string {
                $renderedHtml = $html;

                return '%PDF-1.7 test label';
            });

        $storage = $this->createMock(FilesystemOperator::class);
        $storage
            ->expects(self::once())
            ->method('write')
            ->with(
                self::matchesRegularExpression('#^labels/2026/10/order-1234-[0-9a-f]{16}\.pdf$#'),
                '%PDF-1.7 test label',
            );

        $order = $this->createOrder();
        $renderer = new OrderLabelRenderer(
            self::getContainer()->get(Environment::class),
            $pdfRenderer,
            $storage,
            new BakeryClock('America/Indiana/Indianapolis'),
            new NullLogger(),
        );

        $document = $renderer->render($order);

        self::assertSame('application/pdf', $document->mimeType);
        self::assertSame('%PDF-1.7 test label', $document->getContents());
        self::assertStringContainsString('Snapshot Customer', $renderedHtml);
        self::assertStringContainsString('(812) 555-1234', $renderedHtml);
        self::assertStringContainsString('Sat, Oct 10', $renderedHtml);
        self::assertStringContainsString('9:30 AM', $renderedHtml);
        self::assertStringContainsString('Paid', $renderedHtml);
        self::assertStringContainsString('Order #1234', $renderedHtml);
        self::assertStringContainsString('Counter Employee', $renderedHtml);
        self::assertStringContainsString('2 Dozen', $renderedHtml);
        self::assertStringContainsString('Glazed donuts', $renderedHtml);
        self::assertStringContainsString('1 Box', $renderedHtml);
        self::assertStringContainsString('Brownies', $renderedHtml);
        self::assertTrue(strpos($renderedHtml, '>Donuts<') < strpos($renderedHtml, '>Brownies<'));
        self::assertStringNotContainsString('Current Customer', $renderedHtml);
        self::assertStringNotContainsString('(812) 555-9999', $renderedHtml);
    }

    private function createOrder(): Order
    {
        $customer = (new Customer())
            ->setName('Snapshot Customer')
            ->setPhone(PhoneNumberUtil::getInstance()->parse('+18125551234', 'US'));
        $employee = (new Employee())->setName('Counter Employee');
        $donuts = (new ProductType())->setName('Donuts');
        $brownies = (new ProductType())->setName('Brownies');
        $dozen = (new Unit())->setName('Dozen');
        $box = (new Unit())->setName('Box');
        $order = (new Order())
            ->setOrderNumber('1234')
            ->setCustomer($customer)
            ->setEmployee($employee)
            ->setPickupAt(new \DateTimeImmutable('2026-10-10 09:30:00', new \DateTimeZone('America/Indiana/Indianapolis')))
            ->setOrderedAt(new \DateTimeImmutable('2026-10-07 13:24:00', new \DateTimeZone('America/Indiana/Indianapolis')))
            ->setPaid(true);

        $order->addItem(
            (new OrderItem())
                ->setProductType($brownies)
                ->setQuantity('1')
                ->setUnit($box)
                ->setDescription('No nuts')
                ->setSortOrder(20),
        );
        $order->addItem(
            (new OrderItem())
                ->setProductType($donuts)
                ->setQuantity('2')
                ->setUnit($dozen)
                ->setDescription('Glazed donuts')
                ->setSortOrder(10),
        );

        $customer
            ->setName('Current Customer')
            ->setPhone(PhoneNumberUtil::getInstance()->parse('+18125559999', 'US'));

        return $order;
    }
}
