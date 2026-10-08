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
        //todo-evo: Finish the user crud controller
        yield TextField::new('name', 'Name');
        yield BooleanField::new('active', 'Active');
        yield IntegerField::new('sortOrder', 'Sort order')->setHelp('Lower values appear first when employees are selected for an order.');
    }
}
