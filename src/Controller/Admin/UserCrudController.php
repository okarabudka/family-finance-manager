<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** @extends AbstractCrudController<User> */
class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.entity.user.singular')
            ->setEntityLabelInPlural('admin.entity.user.plural');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'admin.field.id')->hideOnForm();
        yield EmailField::new('email', 'admin.field.email');
        yield TextField::new('name', 'admin.field.name');
        yield TextField::new('password', 'admin.field.password')
            ->setFormType(PasswordType::class)
            ->setRequired($pageName === 'new')
            ->onlyOnForms()
            ->setHelp('admin.help.password');
        yield ChoiceField::new('roles', 'admin.field.roles')
            ->setChoices(['user.role.member_short' => 'ROLE_USER', 'user.role.admin_short' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded();
        yield AssociationField::new('family', 'admin.field.family');
        yield TextField::new('telegramUsername', 'admin.field.telegram_username')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'admin.field.created_at')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance->getPassword() !== '') {
            $entityInstance->setPassword(
                $this->passwordHasher->hashPassword($entityInstance, $entityInstance->getPassword())
            );
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $plain = $entityInstance->getPassword();
        if ($plain === '') {
            $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
            $entityInstance->setPassword(\is_string($original['password'] ?? null) ? $original['password'] : '');
        } else {
            $entityInstance->setPassword(
                $this->passwordHasher->hashPassword($entityInstance, $plain)
            );
        }
        parent::updateEntity($entityManager, $entityInstance);
    }
}
