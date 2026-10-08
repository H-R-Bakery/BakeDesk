<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PackagingRule;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

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
        yield NumberField::new('quantityPerPackage', 'Quantity per package')
            ->setNumDecimals(2)
            ->setStoredAsString(true)
            ->setHelp('Used when the selected Unit is not a package unit. Example: 24 Each Cookies per physical box.');
        yield BooleanField::new('active', 'Active');
    }
}
