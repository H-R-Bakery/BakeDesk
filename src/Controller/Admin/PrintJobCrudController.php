<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PrintJob;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/** @extends AbstractCrudController<PrintJob> */
final class PrintJobCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PrintJob::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setSearchFields(['externalJobId', 'documentPath', 'errorMessage', 'order.orderNumber'])
            ->setDefaultSort(['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices([
                'Queued' => PrintJobStatus::QUEUED->value,
                'Processing' => PrintJobStatus::PROCESSING->value,
                'Rendered' => PrintJobStatus::RENDERED->value,
                'Submitted' => PrintJobStatus::SUBMITTED->value,
                'Completed' => PrintJobStatus::COMPLETED->value,
                'Failed' => PrintJobStatus::FAILED->value,
                'Cancelled' => PrintJobStatus::CANCELLED->value,
            ]))
            ->add(ChoiceFilter::new('documentType')->setChoices([
                'Report' => PrintDocumentType::REPORT->value,
                'Label' => PrintDocumentType::LABEL->value,
            ]))
            ->add(EntityFilter::new('printer')->autocomplete());
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('id', 'ID')->hideOnIndex();
        yield TextField::new('documentType', 'Document type')->formatValue(static fn ($value): string => $value instanceof \BackedEnum ? ucfirst($value->value) : (string) $value);
        yield TextField::new('status', 'Status')->formatValue(static fn ($value): string => $value instanceof \BackedEnum ? ucfirst($value->value) : (string) $value);
        yield AssociationField::new('printer', 'Printer');
        yield AssociationField::new('order', 'Order')->formatValue(static fn ($value): string => null === $value ? '' : 'Order '.$value->getOrderNumber());
        yield AssociationField::new('orderItem', 'Order item');
        yield IntegerField::new('packageNumber', 'Package number');
        yield IntegerField::new('packageCount', 'Package count');
        yield NumberField::new('packageQuantity', 'Package quantity')->setStoredAsString(true)->setNumDecimals(2);
        yield IntegerField::new('attemptCount', 'Attempts');
        yield TextField::new('externalJobId', 'External IPP job ID');
        yield TextField::new('documentPath', 'Document path');
        yield DateField::new('reportDate', 'Report date');
        yield DateTimeField::new('createdAt', 'Created');
        yield DateTimeField::new('startedAt', 'Started');
        yield DateTimeField::new('submittedAt', 'Submitted');
        yield DateTimeField::new('completedAt', 'Completed');
        yield TextField::new('errorMessage', 'Error');
    }
}
