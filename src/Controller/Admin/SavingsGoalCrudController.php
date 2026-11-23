<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SavingsGoal;
use App\Entity\SavingsGoalStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends AbstractCrudController<SavingsGoal> */
class SavingsGoalCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SavingsGoal::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.entity.savings_goal.singular')
            ->setEntityLabelInPlural('admin.entity.savings_goal.plural');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'admin.field.id')->hideOnForm();
        yield AssociationField::new('family', 'admin.field.family');
        yield TextField::new('name', 'admin.field.name');
        yield AssociationField::new('account', 'admin.field.account');
        yield MoneyField::new('targetAmount', 'admin.field.target_amount')->setCurrency('EUR')->setStoredAsCents(false)->hideOnIndex();
        yield DateField::new('targetDate', 'admin.field.target_date')->hideOnIndex();
        yield ChoiceField::new('status', 'admin.field.status')->setChoices(SavingsGoalStatus::cases());
        yield TextField::new('description', 'admin.field.description')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'admin.field.created_at')->hideOnForm();
    }
}
