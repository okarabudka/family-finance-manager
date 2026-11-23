<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\Family;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<mixed> */
class BudgetFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Family $family */
        $family = $options['family'];

        $builder
            ->add('category', EntityType::class, [
                'class'         => Category::class,
                'query_builder' => fn ($repo) => $repo->createFamilyQueryBuilder($family),
                'label'         => 'budget.form.category',
                'choice_label'  => 'name',
            ])
            ->add('plannedAmount', MoneyType::class, [
                'currency' => $family->getCurrency(),
                'label'    => 'budget.form.planned_amount',
            ])
            ->add('year', TextType::class, [
                'label' => 'budget.form.year',
                'attr'  => ['placeholder' => (string) date('Y')],
            ])
            ->add('month', TextType::class, [
                'label' => 'budget.form.month',
                'attr'  => ['placeholder' => (string) date('n')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('family');
        $resolver->setAllowedTypes('family', Family::class);
    }
}
