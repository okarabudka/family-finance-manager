<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Account;
use App\Entity\AccountType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AccountCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Account::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.entity.account.singular')
            ->setEntityLabelInPlural('admin.entity.account.plural');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'admin.field.id')->hideOnForm();
        yield AssociationField::new('family', 'admin.field.family');
        yield TextField::new('name', 'admin.field.name');
        yield ChoiceField::new('type', 'admin.field.type')->setChoices(AccountType::cases());
        yield TextField::new('currency', 'admin.field.currency');
        yield MoneyField::new('initialBalance', 'admin.field.initial_balance')->setCurrency('EUR');
        yield TextField::new('iban', 'admin.field.iban')->hideOnIndex();
        yield BooleanField::new('isActive', 'admin.field.is_active');
        yield BooleanField::new('includeInTotal', 'admin.field.include_in_total');
        yield BooleanField::new('isDefault', 'admin.field.is_default')->hideOnIndex();
    }
}
