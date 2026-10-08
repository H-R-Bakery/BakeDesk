<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Document\GotenbergDocumentRenderer;
use App\Application\Document\OrderLabelRenderer;
use App\Application\Order\BakeryClock;
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
use Symfony\Component\Process\Process;
use Twig\Environment;

final class GotenbergLabelIntegrationTest extends KernelTestCase
{
    public function testRepresentativeLabelIsExactlyFourBySixAndOnePage(): void
    {
        $pdf = $this->renderLabel($this->createOrder());
        $pdfInfo = $this->inspectPdf($pdf);

        self::assertMatchesRegularExpression('/^Pages:\s+1$/m', $pdfInfo);
        self::assertMatchesRegularExpression('/^Page size:\s+288 x 432 pts$/m', $pdfInfo);
        self::assertStringContainsString('PAID', $this->extractText($pdf));
    }

    public function testUnpaidAndLongCustomerLabelsRemainOnePage(): void
    {
        $order = $this->createOrder()
            ->setPaid(false)
            ->setCustomerName('A Customer Name That Is Long Enough To Wrap Safely');

        $pdf = $this->renderLabel($order);
        $pdfInfo = $this->inspectPdf($pdf);
        $text = $this->extractText($pdf);

        self::assertMatchesRegularExpression('/^Pages:\s+1$/m', $pdfInfo);
        self::assertStringContainsString('NOT PAID', $text);
        self::assertMatchesRegularExpression('/A Customer\s+Name\s+That Is\s+Long Enough\s+To\s+Wrap Safely/s', $text);
    }

    public function testSeveralLineItemsRemainOnOnePageInSortOrder(): void
    {
        $order = $this->createOrder();
        foreach ([30 => 'Cookies', 40 => 'Shape Cookies', 50 => 'Donuts', 60 => 'Brownies'] as $sortOrder => $productName) {
            $order->addItem(
                (new OrderItem())
                    ->setProductType((new ProductType())->setName($productName))
                    ->setQuantity('1')
                    ->setUnit((new Unit())->setName('Each'))
                    ->setDescription('Standard assortment')
                    ->setSortOrder($sortOrder),
            );
        }

        $pdf = $this->renderLabel($order);
        $text = $this->extractText($pdf);

        self::assertMatchesRegularExpression('/^Pages:\s+1$/m', $this->inspectPdf($pdf));
        self::assertLessThan(strpos($text, 'Shape Cookies'), strpos($text, 'Donuts'));
    }

    private function renderLabel(Order $order): string
    {
        self::bootKernel();
        $this->skipIfGotenbergIsUnavailable();

        $renderer = new OrderLabelRenderer(
            self::getContainer()->get(Environment::class),
            self::getContainer()->get(GotenbergDocumentRenderer::class),
            $this->createStub(FilesystemOperator::class),
            new BakeryClock('America/Indiana/Indianapolis'),
            new NullLogger(),
        );

        return $renderer->render($order)->getContents();
    }

    private function skipIfGotenbergIsUnavailable(): void
    {
        $baseUrl = $_ENV['GOTENBERG_BASE_URL'] ?? 'http://localhost:18833';
        $handle = curl_init($baseUrl.'/health');
        if (false === $handle) {
            self::markTestSkipped('Gotenberg is unavailable.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (false === $response || 200 !== $status) {
            self::markTestSkipped('Gotenberg is unavailable.');
        }
    }

    private function inspectPdf(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bakedesk-label-');
        self::assertNotFalse($path);
        file_put_contents($path, $pdf);

        try {
            $process = new Process(['pdfinfo', $path]);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            unlink($path);
        }
    }

    private function extractText(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bakedesk-label-text-');
        self::assertNotFalse($path);
        file_put_contents($path, $pdf);

        try {
            $process = new Process(['pdftotext', $path, '-']);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            unlink($path);
        }
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
                ->setProductType($donuts)
                ->setQuantity('2')
                ->setUnit($dozen)
                ->setDescription('Glazed donuts')
                ->setSortOrder(10),
        );
        $order->addItem(
            (new OrderItem())
                ->setProductType($brownies)
                ->setQuantity('1')
                ->setUnit($box)
                ->setDescription('No nuts')
                ->setSortOrder(20),
        );

        return $order;
    }
}
