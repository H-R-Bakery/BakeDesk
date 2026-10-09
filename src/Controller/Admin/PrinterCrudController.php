<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Printing\PrinterDiagnosticsService;
use App\Application\Printing\PrinterStatusException;
use App\Application\Printing\PrinterSubmissionException;
use App\Application\Printing\PrinterTestDocumentRenderingException;
use App\Application\Printing\PrinterTestPrintService;
use App\Application\Printing\PrintJobStatusSnapshot;
use App\Entity\Printer;
use App\Model\PrintDocumentType;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** @extends ReferenceCrudController<Printer> */
final class PrinterCrudController extends ReferenceCrudController
{
    public function __construct(
        private readonly PrinterDiagnosticsService $printerDiagnostics,
        private readonly PrinterTestPrintService $printerTestPrintService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Printer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setSearchFields(['name', 'address'])
            ->setDefaultSort(['name' => 'ASC', 'id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $checkStatus = Action::new('checkStatus', 'Check Status', 'fa fa-heart-pulse')
            ->linkToCrudAction('diagnostics');
        $testLabel = Action::new('testLabel', 'Test Label', 'fa fa-print')
            ->linkToCrudAction('testLabel')
            ->renderAsForm()
            ->setTemplatePath('admin/crud/action_printer_test_label.html.twig')
            ->askConfirmation('Submit a label test page to this printer?', 'Test Label')
            ->displayIf(static fn (Printer $printer): bool => $printer->isActive() && $printer->isForLabels());
        $testReport = Action::new('testReport', 'Test Report', 'fa fa-file-lines')
            ->linkToCrudAction('testReport')
            ->renderAsForm()
            ->setTemplatePath('admin/crud/action_printer_test_report.html.twig')
            ->askConfirmation('Submit a report test page to this printer?', 'Test Report')
            ->displayIf(static fn (Printer $printer): bool => $printer->isActive() && $printer->isForReports());

        return $actions
            ->add(Crud::PAGE_INDEX, $checkStatus)
            ->add(Crud::PAGE_DETAIL, $checkStatus)
            ->add(Crud::PAGE_INDEX, $testLabel)
            ->add(Crud::PAGE_DETAIL, $testLabel)
            ->add(Crud::PAGE_INDEX, $testReport)
            ->add(Crud::PAGE_DETAIL, $testReport);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Name');
        yield TextField::new('address', 'IPP Address')->setHelp('Example: ipp://printer-host/ipp/print');
        yield BooleanField::new('active', 'Active')->renderAsSwitch(Crud::PAGE_INDEX == $pageName ? false : true);
        yield BooleanField::new('forLabels', 'Available for labels')->renderAsSwitch(Crud::PAGE_INDEX == $pageName ? false : true);
        yield BooleanField::new('forReports', 'Available for reports')->renderAsSwitch(Crud::PAGE_INDEX == $pageName ? false : true);
        yield BooleanField::new('defaultForLabels', 'Default label printer')->renderAsSwitch(Crud::PAGE_INDEX == $pageName ? false : true)->setHelp('A default label printer must be active and available for labels.');
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addConfigurationGuard(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addConfigurationGuard(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    #[AdminRoute('/{id}/diagnostics', options: ['methods' => ['GET']])]
    public function diagnostics(Printer $printer): Response
    {
        $status = null;
        $statusError = null;

        try {
            $status = $this->printerDiagnostics->check($printer);
        } catch (\Throwable $exception) {
            $this->logger->error('Printer status check failed.', [
                'printer_id' => $printer->getId(),
                'printer_name' => $printer->getName(),
                'exception' => $exception,
            ]);
            $statusError = sprintf(
                'Unable to contact printer "%s". Check the configured IPP address and printer availability.',
                $printer->getName(),
            );
        }

        return $this->render('admin/printer/diagnostics.html.twig', [
            'printer' => $printer,
            'printer_address' => $this->printerDiagnostics->safeAddress($printer),
            'status' => $status,
            'status_error' => $statusError,
            'state_label' => null === $status ? null : $this->printerDiagnostics->stateLabel($status->state),
            'state_badge_class' => null === $status ? null : $this->printerDiagnostics->stateBadgeClass($status->state),
            'accepts_jobs_label' => null === $status ? null : $this->printerDiagnostics->booleanLabel($status->acceptsJobs),
            'pdf_support_label' => null === $status ? null : $this->printerDiagnostics->booleanLabel($status->supportsDocumentFormat('application/pdf')),
            'reasons' => null === $status ? [] : $this->printerDiagnostics->reasonLabels($status),
            'raw_attributes' => null === $status ? [] : $this->printerDiagnostics->safeRawAttributes($status),
        ]);
    }

    #[AdminRoute('/{id}/test-label', options: ['methods' => ['POST']])]
    public function testLabel(Printer $printer, Request $request): Response
    {
        return $this->submitTest($printer, $request, PrintDocumentType::LABEL, 'printer-test-label-');
    }

    #[AdminRoute('/{id}/test-report', options: ['methods' => ['POST']])]
    public function testReport(Printer $printer, Request $request): Response
    {
        return $this->submitTest($printer, $request, PrintDocumentType::REPORT, 'printer-test-report-');
    }

    private function addConfigurationGuard(FormBuilderInterface $builder): FormBuilderInterface
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();
            if (!is_array($submitted)) {
                return;
            }

            $active = isset($submitted['active']) && '0' !== (string) $submitted['active'];
            $forLabels = isset($submitted['forLabels']) && '0' !== (string) $submitted['forLabels'];
            $defaultForLabels = isset($submitted['defaultForLabels']) && '0' !== (string) $submitted['defaultForLabels'];
            $printer = $event->getForm()->getData();

            if ($defaultForLabels && (!$active || !$forLabels)) {
                $event->getForm()->addError(new FormError('A default label printer must be active and available for labels.'));
                unset($submitted['defaultForLabels']);
                $event->setData($submitted);

                return;
            }

            if ($printer instanceof Printer && !$defaultForLabels && $printer->isDefaultForLabels()) {
                $printer->setDefaultForLabels(false);
            }
        });

        return $builder;
    }

    private function submitTest(Printer $printer, Request $request, PrintDocumentType $documentType, string $csrfPrefix): Response
    {
        $printerId = $printer->getId();
        if (null === $printerId || !$this->isCsrfTokenValid($csrfPrefix.$printerId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $testName = PrintDocumentType::LABEL === $documentType ? 'label' : 'report';
        try {
            $submission = $this->printerTestPrintService->submit($printer, $documentType);
            $message = sprintf(
                'Test %s submitted to "%s". IPP job ID: %s.',
                $testName,
                $printer->getName(),
                $submission->externalJobId,
            );
            if (null !== $submission->initialStatus) {
                $message .= sprintf(' Initial IPP status: %s.', $this->initialStatusLabel($submission->initialStatus));
            }
            $this->addFlash('success', $message);
        } catch (PrinterSubmissionException $exception) {
            $this->logger->warning('Printer test submission was rejected.', [
                'printer_id' => $printerId,
                'document_type' => $documentType->value,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', sprintf('Test %s could not be submitted: %s', $testName, $exception->getMessage()));
        } catch (PrinterStatusException|PrinterTestDocumentRenderingException $exception) {
            $this->logger->error('Printer test could not be prepared or submitted.', [
                'printer_id' => $printerId,
                'document_type' => $documentType->value,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', sprintf('Test %s could not be submitted: %s', $testName, $exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->logger->error('Printer test submission failed.', [
                'printer_id' => $printerId,
                'document_type' => $documentType->value,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', sprintf('Unable to submit the %s printer test.', $testName));
        }

        return $this->redirect($this->adminUrlGenerator->setAction(Action::DETAIL)->generateUrl());
    }

    private function initialStatusLabel(PrintJobStatusSnapshot $status): string
    {
        return ucfirst(str_replace('-', ' ', $status->state->value));
    }
}
