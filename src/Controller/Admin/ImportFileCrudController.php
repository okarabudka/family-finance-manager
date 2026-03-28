<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ImportFile;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
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
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('family');
        yield TextField::new('originalFilename');
        yield TextField::new('format');
        yield TextField::new('status');
        yield IntegerField::new('totalRows');
        yield IntegerField::new('processedRows');
        yield TextField::new('errorMessage')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
