<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Model\OrderStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** @extends AbstractCrudController<Order> */
final class OrderCrudController extends AbstractCrudController
{
    public function __construct(
        #[Autowire('%bakery_timezone%')]
        private readonly string $bakeryTimezone,
        #[Autowire('%bakedesk_brand_name%')]
        private readonly string $bakedeskBrandName,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle(Crud::PAGE_INDEX, 'Orders')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Order $order): string => 'Order #'.$order->getOrderNumber())
            ->setSearchFields(['orderNumber', 'customerName', 'customerPhone', 'notes'])
            ->setDefaultSort(['pickupAt' => 'DESC', 'id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $openInBakeDesk = Action::new('openInBakeDesk', 'Open in '.$this->bakedeskBrandName, 'fa fa-arrow-up-right-from-square')
            ->linkToUrl(fn (Order $order): string => $this->urlGenerator->generate('order_detail', ['id' => $order->getId()]));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $openInBakeDesk)
            ->add(Crud::PAGE_DETAIL, $openInBakeDesk)
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices([
                'Open' => OrderStatus::OPEN->value,
                'Completed' => OrderStatus::COMPLETED->value,
                'Cancelled' => OrderStatus::CANCELLED->value,
            ]))
            ->add(BooleanFilter::new('paid'))
            ->add(DateTimeFilter::new('pickupAt'))
            ->add(EntityFilter::new('user')->setLabel('Order taker')->autocomplete());
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'ID')->hideOnIndex()->hideOnForm();
        yield TextField::new('orderNumber', 'Order number')->hideOnForm();
        yield TextField::new('customerName', 'Customer name')->hideOnForm();
        yield TextField::new('customerPhone', 'Customer phone')
            ->formatValue(static fn ($phone): string => null === $phone ? '' : \libphonenumber\PhoneNumberUtil::getInstance()->format($phone, \libphonenumber\PhoneNumberFormat::NATIONAL))
            ->hideOnForm()
        ;
        yield AssociationField::new('customer', 'Customer')->hideOnForm();
        yield AssociationField::new('user', 'Order taker')->hideOnForm();
        yield DateTimeField::new('pickupAt', 'Pickup')
            ->setTimezone($this->bakeryTimezone)
            ->setFormat(DateTimeField::FORMAT_MEDIUM, DateTimeField::FORMAT_SHORT);
        yield DateTimeField::new('orderedAt', 'Ordered')
            ->setTimezone($this->bakeryTimezone)
            ->setFormat(DateTimeField::FORMAT_MEDIUM, DateTimeField::FORMAT_SHORT)
            ->hideOnForm()
        ;
        yield BooleanField::new('paid', 'Paid')->renderAsSwitch($pageName == Crud::PAGE_INDEX ? false : true);
        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'Open' => OrderStatus::OPEN,
                'Completed' => OrderStatus::COMPLETED,
                'Cancelled' => OrderStatus::CANCELLED,
            ])
            ->renderAsBadges([
                OrderStatus::OPEN->value => 'primary',
                OrderStatus::COMPLETED->value => 'success',
                OrderStatus::CANCELLED->value => 'danger',
            ]);
        yield TextareaField::new('notes', 'Notes')->hideOnIndex();
        yield CollectionField::new('items', 'Order items')
            ->onlyOnDetail()
            ->setEntryToStringMethod(static fn (OrderItem $item): string => sprintf(
                '%s %s · %s · %s',
                $item->getQuantity(),
                $item->getUnit()?->getName() ?? 'Unit',
                $item->getProductType()?->getName() ?? 'Product',
                $item->getDescription(),
            ))
            ->hideOnForm()
        ;
        yield DateTimeField::new('createdAt', 'Created')
            ->setTimezone($this->bakeryTimezone)
            ->hideOnIndex()
            ->hideOnForm()
        ;
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setTimezone($this->bakeryTimezone)
            ->hideOnIndex()
            ->hideOnForm()
        ;
    }
}
