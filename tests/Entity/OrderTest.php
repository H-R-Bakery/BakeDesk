<?php

namespace App\Tests\Entity;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Model\OrderStatus;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function testNewOrderDefaultsToOpenAndUnpaid(): void
    {
        $order = new Order();

        self::assertSame(OrderStatus::OPEN, $order->getStatus());
        self::assertFalse($order->isPaid());
    }

    public function testAddAndRemoveItemKeepsBothSidesConsistent(): void
    {
        $order = new Order();
        $item = new OrderItem();

        $order->addItem($item);

        self::assertTrue($order->getItems()->contains($item));
        self::assertSame($order, $item->getOrder());

        $order->removeItem($item);

        self::assertFalse($order->getItems()->contains($item));
        self::assertNull($item->getOrder());
    }

    public function testCustomerSnapshotsRemainIndependentOfCustomerChanges(): void
    {
        $phoneUtil = PhoneNumberUtil::getInstance();
        $originalPhone = $phoneUtil->parse('+13175550123', 'US');
        $replacementPhone = $phoneUtil->parse('+13175550124', 'US');
        $customer = (new Customer())
            ->setName('Original Customer')
            ->setPhone($originalPhone);
        $order = (new Order())->setCustomer($customer);

        $customer
            ->setName('Updated Customer')
            ->setPhone($replacementPhone);

        self::assertSame('Original Customer', $order->getCustomerName());
        self::assertNotSame($replacementPhone, $order->getCustomerPhone());
        self::assertSame(
            '+13175550123',
            $phoneUtil->format($order->getCustomerPhone(), PhoneNumberFormat::E164),
        );
    }

    public function testFractionalQuantityUsesAStringRepresentation(): void
    {
        $item = (new OrderItem())->setQuantity('2.25');

        self::assertSame('2.25', $item->getQuantity());
        self::assertIsString($item->getQuantity());
    }
}
