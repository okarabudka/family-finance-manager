<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ImportFile;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ImportFileCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ImportFile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.entity.import_file.singular')
            ->setEntityLabelInPlural('admin.entity.import_file.plural')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'admin.field.id')->hideOnForm();
        yield AssociationField::new('account', 'admin.field.account');
        yield TextField::new('originalFilename', 'admin.field.original_filename');
        yield TextField::new('format', 'admin.field.format');
        yield ChoiceField::new('status', 'admin.field.status');
        yield IntegerField::new('totalRows', 'admin.field.total_rows');
        yield IntegerField::new('importedRows', 'admin.field.imported_rows');
        yield TextField::new('errorMessage', 'admin.field.error_message')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'admin.field.created_at')->hideOnForm();
    }
}
