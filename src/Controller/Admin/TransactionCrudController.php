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

/** @extends AbstractCrudController<Transaction> */
class TransactionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transaction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.entity.transaction.singular')
            ->setEntityLabelInPlural('admin.entity.transaction.plural')
            ->setDefaultSort(['executedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'admin.field.id')->hideOnForm();
        yield AssociationField::new('family', 'admin.field.family');
        yield ChoiceField::new('type', 'admin.field.type')->setChoices(TransactionType::cases());
        yield MoneyField::new('amount', 'admin.field.amount')->setCurrency('EUR');
        yield TextField::new('currency', 'admin.field.currency');
        yield DateField::new('executedAt', 'admin.field.executed_at');
        yield AssociationField::new('category', 'admin.field.category');
        yield AssociationField::new('sourceAccount', 'admin.field.source_account');
        yield AssociationField::new('targetAccount', 'admin.field.target_account')->hideOnIndex();
        yield TextField::new('description', 'admin.field.description')->hideOnIndex();
        yield AssociationField::new('createdBy', 'admin.field.created_by')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'admin.field.created_at')->hideOnForm();
    }
}
