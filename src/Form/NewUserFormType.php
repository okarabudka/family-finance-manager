<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<mixed> */
class NewUserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'user.form.name',
                'constraints' => [new NotBlank(), new Length(min: 2, max: 100)],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'user.form.email',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('password', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'first_options'   => ['label' => 'user.form.password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options'  => ['label' => 'user.form.password_confirm', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'user.password.mismatch',
                'constraints'     => [new NotBlank(), new Length(min: 6)],
            ])
            ->add('roles', ChoiceType::class, [
                'label'    => 'user.form.role',
                'mapped'   => false,
                'choices'  => [
                    'user.role.member' => 'ROLE_USER',
                    'user.role.admin' => 'ROLE_ADMIN',
                ],
                'expanded' => true,
                'multiple' => false,
                'data'     => 'ROLE_USER',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id'   => 'new_user',
        ]);
    }
}
