<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Document\DocumentRendererInterface;
use App\Application\Document\OrderLabelRenderer;
use App\Application\Order\BakeryClock;
use App\Application\Packaging\PackageAllocation;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\ProductType;
use App\Entity\Unit;
use App\Entity\User;
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
                self::matchesRegularExpression('#^labels/2026/10/order-1234-[0-9]{10}\.pdf$#'),
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
        self::assertStringContainsString('1:30 PM', $renderedHtml);
        self::assertStringContainsString('payment-paid', $renderedHtml);
        self::assertMatchesRegularExpression('/class="payment payment-paid">\s*PAID\s*<\/div>/', $renderedHtml);
        self::assertStringContainsString('Order #1234', $renderedHtml);
        self::assertStringContainsString('Counter Employee', $renderedHtml);
        self::assertStringContainsString('2 Dozen', $renderedHtml);
        self::assertStringContainsString('Glazed donuts', $renderedHtml);
        self::assertStringContainsString('1 Box', $renderedHtml);
        self::assertStringContainsString('Brownies', $renderedHtml);
        self::assertTrue(strpos($renderedHtml, '>Donuts<') < strpos($renderedHtml, '>Brownies<'));
        self::assertStringContainsString('class="label-top"', $renderedHtml);
        self::assertStringContainsString('class="label-bottom"', $renderedHtml);
        self::assertMatchesRegularExpression('/\.label-bottom\s*\{.*?top:\s*4in;/s', $renderedHtml);
        self::assertMatchesRegularExpression('/\.fold-line\s*\{.*?top:\s*4in;/s', $renderedHtml);
        self::assertStringContainsString('class="payment payment-paid"', $renderedHtml);
        $logo = file_get_contents(dirname(__DIR__, 2).'/assets/Images/HRBakeryLogo_bw.svg');
        self::assertIsString($logo);
        self::assertStringContainsString($logo, $renderedHtml);
        self::assertStringNotContainsString('Current Customer', $renderedHtml);
        self::assertStringNotContainsString('(812) 555-9999', $renderedHtml);
    }

    public function testUnpaidLabelUsesBlackBackgroundAndWhiteText(): void
    {
        $renderedHtml = $this->renderLabel($this->createOrder()->setPaid(false));

        self::assertStringContainsString('class="payment payment-unpaid"', $renderedHtml);
        self::assertMatchesRegularExpression('/class="payment payment-unpaid">\s*NOT PAID\s*<\/div>/', $renderedHtml);
        self::assertStringContainsString('background: #000;', $renderedHtml);
        self::assertStringContainsString('color: #fff;', $renderedHtml);
    }

    public function testPackageLabelContainsOnlyItsAllocatedItemAndBoxPosition(): void
    {
        $order = $this->createOrder();
        $donuts = $order->getItems()->toArray()[1];
        $allocation = new PackageAllocation($donuts, 1, 2, '1', $donuts->getUnit());
        $renderedHtml = $this->renderLabel($allocation);

        self::assertStringContainsString('1 Dozen', $renderedHtml);
        self::assertStringContainsString('Glazed donuts', $renderedHtml);
        self::assertStringContainsString('BOX 1 OF 2', $renderedHtml);
        self::assertStringNotContainsString('Brownies', $renderedHtml);
        self::assertStringContainsString('Snapshot Customer', $renderedHtml);
        self::assertStringContainsString('Order #1234', $renderedHtml);
    }

    public function testTooMuchItemContentFailsBeforePdfRendering(): void
    {
        self::bootKernel();

        $pdfRenderer = $this->createMock(DocumentRendererInterface::class);
        $pdfRenderer->expects(self::never())->method('renderHtmlToPdf');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('write');

        $order = $this->createOrder();
        for ($sortOrder = 30; $sortOrder <= 90; $sortOrder += 10) {
            $order->addItem(
                (new OrderItem())
                    ->setProductType((new ProductType())->setName('Cookies'))
                    ->setQuantity('1')
                    ->setUnit((new Unit())->setName('Each'))
                    ->setDescription('A deliberately long description that cannot fit in the fixed label item area.')
                    ->setSortOrder($sortOrder),
            );
        }

        $renderer = new OrderLabelRenderer(
            self::getContainer()->get(Environment::class),
            $pdfRenderer,
            $storage,
            new BakeryClock('America/Indiana/Indianapolis'),
            new NullLogger(),
        );

        $this->expectException(\App\Application\Document\OrderLabelRenderingException::class);
        $renderer->render($order);
    }

    private function renderLabel(Order|PackageAllocation $subject): string
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

        $storage = $this->createStub(FilesystemOperator::class);
        $renderer = new OrderLabelRenderer(
            self::getContainer()->get(Environment::class),
            $pdfRenderer,
            $storage,
            new BakeryClock('America/Indiana/Indianapolis'),
            new NullLogger(),
        );

        $renderer->render($subject);

        return $renderedHtml;
    }

    private function createOrder(): Order
    {
        $customer = (new Customer())
            ->setName('Snapshot Customer')
            ->setPhone(PhoneNumberUtil::getInstance()->parse('+18125551234', 'US'));
        $user = User::new(email: 'ce@example.com', name: 'Counter Employee', employee: true)->setPlainPassword('test-password');
        $donuts = (new ProductType())->setName('Donuts');
        $brownies = (new ProductType())->setName('Brownies');
        $dozen = (new Unit())->setName('Dozen');
        $box = (new Unit())->setName('Box');
        $order = (new Order())
            ->setOrderNumber('1234')
            ->setCustomer($customer)
            ->setUser($user)
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
