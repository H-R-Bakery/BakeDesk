<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PackagingRule;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/** @extends ReferenceCrudController<PackagingRule> */
final class PackagingRuleCrudController extends ReferenceCrudController
{
    public static function getEntityFqcn(): string
    {
        return PackagingRule::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['productType.name', 'unit.name'])->setDefaultSort(['productType' => 'ASC', 'unit' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Rule')->onlyOnIndex();
        yield AssociationField::new('productType', 'Product type')->autocomplete()->setRequired(true);
        yield AssociationField::new('unit', 'Unit')->autocomplete()->setRequired(true);
        yield IntegerField::new('quantityPerPackage', 'Quantity per package')
            ->setHelp('Optional. Defines how many compatible lines of this Product Type and Unit fit in one physical package. If no active rule exists, the complete compatible group is one package.');
        yield BooleanField::new('active', 'Active')->renderAsSwitch(Crud::PAGE_INDEX == $pageName ? false : true);
    }
}
