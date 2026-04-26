<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield TextField::new('name');
        yield TextField::new('password')
            ->setFormType(PasswordType::class)
            ->setRequired($pageName === 'new')
            ->onlyOnForms()
            ->setHelp('Leave blank to keep the current password (edit only).');
        yield ChoiceField::new('roles')
            ->setChoices(['User' => 'ROLE_USER', 'Admin' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded();
        yield AssociationField::new('family');
        yield TextField::new('telegramUsername')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance instanceof User && $entityInstance->getPassword() !== '') {
            $entityInstance->setPassword(
                $this->passwordHasher->hashPassword($entityInstance, $entityInstance->getPassword())
            );
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            $plain = $entityInstance->getPassword();
            if ($plain === '') {
                $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
                $entityInstance->setPassword($original['password'] ?? '');
            } else {
                $entityInstance->setPassword(
                    $this->passwordHasher->hashPassword($entityInstance, $plain)
                );
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
    }
}
