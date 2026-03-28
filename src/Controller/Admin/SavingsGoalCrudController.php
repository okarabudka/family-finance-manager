<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SavingsGoal;
use App\Entity\SavingsGoalStatus;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SavingsGoalCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SavingsGoal::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('family');
        yield TextField::new('name');
        yield AssociationField::new('account');
        yield MoneyField::new('targetAmount')->setCurrency('EUR')->hideOnIndex();
        yield DateField::new('targetDate')->hideOnIndex();
        yield ChoiceField::new('status')->setChoices(SavingsGoalStatus::cases());
        yield TextField::new('description')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
