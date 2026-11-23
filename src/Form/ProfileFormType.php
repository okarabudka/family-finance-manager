<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
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
class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'Name',
                'constraints' => [new NotBlank(), new Length(min: 2, max: 100)],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('currentPassword', PasswordType::class, [
                'label'    => 'Current Password',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['autocomplete' => 'current-password'],
                'help'     => 'Required only when changing password.',
            ])
            ->add('newPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'required'        => false,
                'first_options'   => ['label' => 'New Password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options'  => ['label' => 'Confirm New Password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Passwords do not match.',
                'constraints'     => [new Length(min: 6)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id'   => 'profile',
        ]);
    }
}
