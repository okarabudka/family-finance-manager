<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Account;
use App\Entity\AccountType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<\App\Entity\Account> */
class AccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Account Name',
                'constraints' => [
                    new NotBlank(message: 'Please enter an account name.'),
                    new Length(max: 100),
                ],
                'attr' => ['placeholder' => 'e.g. Main Checking'],
            ])
            ->add('type', EnumType::class, [
                'class' => AccountType::class,
                'label' => 'Account Type',
            ])
            ->add('currency', ChoiceType::class, [
                'label'   => 'Currency',
                'choices' => [
                    'EUR' => 'EUR',
                    'USD' => 'USD',
                    'GBP' => 'GBP',
                    'CHF' => 'CHF',
                    'PLN' => 'PLN',
                    'CZK' => 'CZK',
                    'RUB' => 'RUB',
                ],
            ])
            ->add('initialBalance', TextType::class, [
                'label'    => 'Initial Balance',
                'required' => false,
                'attr'     => ['placeholder' => '0.00'],
            ])
            ->add('iban', TextType::class, [
                'label'    => 'IBAN',
                'required' => false,
                'attr'     => ['placeholder' => 'Optional'],
            ])
            ->add('includeInTotal', CheckboxType::class, [
                'label'    => 'Include in total balance',
                'required' => false,
            ])
            ->add('isDefault', CheckboxType::class, [
                'label'    => 'Set as default account',
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label'    => 'Sort Order',
                'required' => false,
                'data'     => 0,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
        ]);
    }
}
