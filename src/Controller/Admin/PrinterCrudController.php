<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Printer;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/** @extends ReferenceCrudController<Printer> */
final class PrinterCrudController extends ReferenceCrudController
{
    public static function getEntityFqcn(): string
    {
        return Printer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['name', 'address'])->setDefaultSort(['name' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Name');
        yield TextField::new('address', 'IPP Address')->setHelp('Example: ipp://printer-host/ipp/print');
        yield BooleanField::new('active', 'Active')->renderAsSwitch($pageName == Crud::PAGE_INDEX ? false : true);
        yield BooleanField::new('forLabels', 'Available for labels')->renderAsSwitch($pageName == Crud::PAGE_INDEX ? false : true);
        yield BooleanField::new('forReports', 'Available for reports')->renderAsSwitch($pageName == Crud::PAGE_INDEX ? false : true);
        yield BooleanField::new('defaultForLabels', 'Default label printer')->renderAsSwitch($pageName == Crud::PAGE_INDEX ? false : true)->setHelp('A default label printer must be active and available for labels.');
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
}
