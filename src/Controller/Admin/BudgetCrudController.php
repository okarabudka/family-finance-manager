<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Budget;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class BudgetCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Budget::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.entity.budget.singular')
            ->setEntityLabelInPlural('admin.entity.budget.plural')
            ->setDefaultSort(['year' => 'DESC', 'month' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'admin.field.id')->hideOnForm();
        yield AssociationField::new('family', 'admin.field.family');
        yield AssociationField::new('category', 'admin.field.category');
        yield IntegerField::new('year', 'admin.field.year');
        yield IntegerField::new('month', 'admin.field.month');
        yield MoneyField::new('plannedAmount', 'admin.field.planned_amount')->setCurrency('EUR');
    }
}
