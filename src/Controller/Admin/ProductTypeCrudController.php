<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProductType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends ReferenceCrudController<ProductType> */
final class ProductTypeCrudController extends ReferenceCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProductType::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['name'])->setDefaultSort(['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Name');
        yield BooleanField::new('active', 'Active')->renderAsSwitch($pageName == Crud::PAGE_INDEX ? false : true);
        yield IntegerField::new('sortOrder', 'Sort order')->setHelp('Lower values appear first in order entry and reports.');
    }
}
