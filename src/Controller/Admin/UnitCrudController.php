<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Unit;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends ReferenceCrudController<Unit> */
final class UnitCrudController extends ReferenceCrudController
{
    public static function getEntityFqcn(): string
    {
        return Unit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['name', 'abbreviation'])->setDefaultSort(['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Name');
        yield TextField::new('abbreviation', 'Abbreviation')->setRequired(false);
        yield BooleanField::new('packageUnit', 'Physical package unit')
            ->setHelp('When enabled, each unit represents one physical package and therefore one label. Examples: 2 Dozen = 2 packages; 1 Tray = 1 package.');
        yield BooleanField::new('active', 'Active');
        yield IntegerField::new('sortOrder', 'Sort order')->setHelp('Lower values appear first in order entry and reports.');
    }
}
