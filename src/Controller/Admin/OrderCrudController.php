<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Order\BakeryClock;
use App\Application\Order\OrderCanceller;
use App\Application\Order\OrderCompleter;
use App\Application\Order\OrderReopener;
use App\Application\Realtime\OrderRealtimePublisher;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Model\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly BakeryClock $bakeryClock,
        private readonly OrderCompleter $orderCompleter,
        private readonly OrderReopener $orderReopener,
        private readonly OrderCanceller $orderCanceller,
        private readonly OrderRealtimePublisher $realtimePublisher,
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
        yield BooleanField::new('paid', 'Paid')->renderAsSwitch(Crud::PAGE_INDEX == $pageName ? false : true);
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

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $originalData = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $originalStatus = $this->getOriginalStatus($originalData);
        $submittedStatus = $entityInstance->getStatus();

        if ($submittedStatus !== $originalStatus) {
            // EasyAdmin has already mapped the submitted status. Restore the persisted
            // status long enough for the lifecycle service to validate its precondition.
            $entityInstance->setStatus($originalStatus);

            try {
                match ($submittedStatus) {
                    OrderStatus::COMPLETED => $this->orderCompleter->complete($entityInstance),
                    OrderStatus::OPEN => $this->orderReopener->reopen($entityInstance),
                    OrderStatus::CANCELLED => $this->orderCanceller->cancel($entityInstance),
                };
            } catch (\LogicException $exception) {
                $this->restoreOriginalEditableFields($entityInstance, $originalData, $originalStatus);
                $this->addFlash('danger', sprintf(
                    'Order status change rejected: %s',
                    $exception->getMessage(),
                ));
            }

            return;
        }

        $wasPaid = (bool) ($originalData['paid'] ?? false);
        $entityInstance->setUpdatedAt($this->bakeryClock->now());

        $entityManager->wrapInTransaction(function () use ($entityManager, $entityInstance): void {
            $entityManager->persist($entityInstance);
        });

        if ($wasPaid !== $entityInstance->isPaid()) {
            $this->realtimePublisher->publishOrderUpdated($entityInstance);
        }
    }

    /**
     * @param array<string, mixed> $originalData
     */
    private function getOriginalStatus(array $originalData): OrderStatus
    {
        $status = $originalData['status'] ?? null;

        if ($status instanceof OrderStatus) {
            return $status;
        }

        if (is_string($status)) {
            return OrderStatus::from($status);
        }

        throw new \LogicException('The persisted Order status could not be determined.');
    }

    /**
     * @param array<string, mixed> $originalData
     */
    private function restoreOriginalEditableFields(Order $order, array $originalData, OrderStatus $originalStatus): void
    {
        $pickupAt = $originalData['pickupAt'] ?? null;
        if ($pickupAt instanceof \DateTimeImmutable) {
            $order->setPickupAt($pickupAt);
        }

        $order
            ->setPaid((bool) ($originalData['paid'] ?? false))
            ->setStatus($originalStatus)
            ->setNotes($originalData['notes'] ?? null)
            ->setUpdatedAt($originalData['updatedAt'] ?? null);
    }
}
