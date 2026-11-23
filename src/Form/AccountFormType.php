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
                'label' => 'account.form.name',
                'constraints' => [
                    new NotBlank(message: 'account.name.not_blank'),
                    new Length(max: 100),
                ],
                'attr' => ['placeholder' => 'account.form.name_placeholder'],
            ])
            ->add('type', EnumType::class, [
                'class' => AccountType::class,
                'label' => 'account.form.type',
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'account.form.currency',
                'choice_translation_domain' => false,
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
                'label' => 'account.form.initial_balance',
                'required' => false,
                'attr' => ['placeholder' => '0.00'],
            ])
            ->add('iban', TextType::class, [
                'label' => 'account.form.iban',
                'required' => false,
                'attr' => ['placeholder' => 'common.optional'],
            ])
            ->add('includeInTotal', CheckboxType::class, [
                'label' => 'account.form.include_in_total',
                'required' => false,
            ])
            ->add('isDefault', CheckboxType::class, [
                'label' => 'account.form.is_default',
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'common.sort_order',
                'required' => false,
                'data' => 0,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
        ]);
    }
}
