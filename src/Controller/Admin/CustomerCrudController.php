<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Customer;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Misd\PhoneNumberBundle\Form\Type\PhoneNumberType;

/** @extends ReferenceCrudController<Customer> */
final class CustomerCrudController extends ReferenceCrudController
{
    public static function getEntityFqcn(): string
    {
        return Customer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['name', 'phone'])->setDefaultSort(['name' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Name');
        yield TelephoneField::new('phone', 'Phone')
            ->setFormType(PhoneNumberType::class)
            ->setFormTypeOption('default_region', 'US')
            ->setFormTypeOption('number_type', PhoneNumberType::NUMBER_TYPE_TEL)
            ->formatValue(static fn ($phone): string => null === $phone ? '' : \libphonenumber\PhoneNumberUtil::getInstance()->format($phone, \libphonenumber\PhoneNumberFormat::NATIONAL));
        yield BooleanField::new('active', 'Active')->renderAsSwitch($pageName == Crud::PAGE_INDEX ? false : true);
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
