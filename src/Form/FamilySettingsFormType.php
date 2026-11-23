<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Family;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<Family> */
class FamilySettingsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'settings.family.form.name',
                'constraints' => [new NotBlank(), new Length(min: 1, max: 100)],
            ])
            ->add('currency', ChoiceType::class, [
                'label'   => 'settings.family.form.currency',
                'choices' => [
                    'currency.eur' => 'EUR',
                    'currency.usd' => 'USD',
                    'currency.gbp' => 'GBP',
                    'currency.chf' => 'CHF',
                    'currency.rub' => 'RUB',
                    'currency.pln' => 'PLN',
                    'currency.czk' => 'CZK',
                    'currency.uah' => 'UAH',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Family::class,
            'csrf_protection' => true,
            'csrf_token_id'   => 'family_settings',
        ]);
    }
}
