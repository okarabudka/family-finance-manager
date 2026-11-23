<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\CategoryType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.entity.category.singular')
            ->setEntityLabelInPlural('admin.entity.category.plural');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'admin.field.id')->hideOnForm();
        yield AssociationField::new('family', 'admin.field.family');
        yield TextField::new('name', 'admin.field.name');
        yield ChoiceField::new('type', 'admin.field.type')->setChoices(CategoryType::cases());
        yield TextField::new('icon', 'admin.field.icon')->hideOnIndex();
        yield BooleanField::new('isSystem', 'admin.field.is_system')->hideOnIndex();
        yield AssociationField::new('parent', 'admin.field.parent')->hideOnIndex();
    }
}
