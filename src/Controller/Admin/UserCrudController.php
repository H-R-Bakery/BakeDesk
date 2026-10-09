<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends ReferenceCrudController<User> */
final class UserCrudController extends ReferenceCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['name'])->setDefaultSort(['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('email', 'Email');
        yield TextField::new('name', 'Name');
        yield BooleanField::new('active', 'Active')->renderAsSwitch(Crud::PAGE_INDEX == $pageName ? false : true);
        yield BooleanField::new('employee', 'Available as order taker')
            ->renderAsSwitch(Crud::PAGE_INDEX == $pageName ? false : true)
            ->setHelp('Only active users enabled as order takers appear on the New Order form.');
        yield IntegerField::new('sortOrder', 'Sort order')->setHelp('Lower values appear first when order takers are selected for an order.');
        yield TextField::new('plainPassword', 'Password')->setHelp('Leave blank to keep the current password.');
    }
}
