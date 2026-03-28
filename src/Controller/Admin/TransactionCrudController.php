<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Transaction;
use App\Entity\TransactionType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TransactionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transaction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['executedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('family');
        yield ChoiceField::new('type')->setChoices(TransactionType::cases());
        yield MoneyField::new('amount')->setCurrency('EUR');
        yield TextField::new('currency');
        yield DateField::new('executedAt');
        yield AssociationField::new('category');
        yield AssociationField::new('sourceAccount');
        yield AssociationField::new('targetAccount')->hideOnIndex();
        yield TextField::new('description')->hideOnIndex();
        yield AssociationField::new('createdBy')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
