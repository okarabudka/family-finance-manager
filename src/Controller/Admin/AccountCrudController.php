<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Account;
use App\Entity\AccountType;
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

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('family');
        yield TextField::new('name');
        yield ChoiceField::new('type')->setChoices(AccountType::cases());
        yield TextField::new('currency');
        yield MoneyField::new('initialBalance')->setCurrency('EUR');
        yield TextField::new('iban')->hideOnIndex();
        yield BooleanField::new('isActive');
        yield BooleanField::new('includeInTotal');
        yield BooleanField::new('isDefault')->hideOnIndex();
    }
}
