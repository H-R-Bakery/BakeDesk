<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Printing\PrintJobCanceller;
use App\Application\Printing\PrintJobDeleter;
use App\Entity\PrintJob;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** @extends AbstractCrudController<PrintJob> */
final class PrintJobCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PrintJobCanceller $canceller,
        private readonly PrintJobDeleter $deleter,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PrintJob::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setSearchFields(['externalJobId', 'documentPath', 'errorMessage', 'order.orderNumber'])
            ->setDefaultSort(['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $cancel = Action::new('cancel', 'Cancel', 'fa fa-ban')
            ->linkToCrudAction('cancel')
            ->renderAsForm()
            ->setTemplatePath('admin/crud/action_cancel.html.twig')
            ->askConfirmation('Cancel this print job?', 'Cancel job')
            ->asWarningAction()
            ->displayIf(fn (PrintJob $printJob): bool => $this->isCancellable($printJob));
        $openOrder = Action::new('openOrder', 'Open Order', 'fa fa-receipt')
            ->linkToUrl(fn (PrintJob $printJob): string => $this->urlGenerator->generate('order_detail', ['id' => $printJob->getOrder()?->getId()]))
            ->displayIf(static fn (PrintJob $printJob): bool => null !== $printJob->getOrder());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $cancel)
            ->add(Crud::PAGE_INDEX, $openOrder)
            ->add(Crud::PAGE_DETAIL, $cancel)
            ->add(Crud::PAGE_DETAIL, $openOrder)
            ->disable(Action::NEW, Action::EDIT, Action::BATCH_DELETE)
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action): Action => $action->displayIf(fn (PrintJob $printJob): bool => $this->isDeletable($printJob)))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, fn (Action $action): Action => $action->displayIf(fn (PrintJob $printJob): bool => $this->isDeletable($printJob)));
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
        yield ChoiceField::new('documentType', 'Document type');
        yield ChoiceField::new('status', 'Status');
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

    #[AdminRoute('/{id}/cancel', options: ['methods' => ['POST']])]
    public function cancel(PrintJob $printJob, Request $request, AdminUrlGeneratorInterface $adminUrlGenerator): Response
    {
        $printJobId = $printJob->getId();
        if (null === $printJobId || !$this->isCsrfTokenValid('print-job-cancel-'.$printJobId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->canceller->cancel($printJob);
        } catch (\LogicException $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }

        $this->addFlash('success', sprintf('Print job #%d cancelled.', $printJobId));

        return $this->redirect($adminUrlGenerator->setAction(Action::INDEX)->generateUrl());
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        try {
            $this->deleter->delete($entityInstance);
        } catch (\LogicException $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }
    }

    private function isCancellable(PrintJob $printJob): bool
    {
        return in_array($printJob->getStatus(), [
            PrintJobStatus::QUEUED,
            PrintJobStatus::PROCESSING,
            PrintJobStatus::RENDERED,
        ], true);
    }

    private function isDeletable(PrintJob $printJob): bool
    {
        return in_array($printJob->getStatus(), [
            PrintJobStatus::COMPLETED,
            PrintJobStatus::FAILED,
            PrintJobStatus::CANCELLED,
        ], true);
    }
}
