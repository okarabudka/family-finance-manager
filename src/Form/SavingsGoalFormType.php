<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Account;
use App\Entity\Family;
use App\Entity\SavingsGoal;
use App\Entity\SavingsGoalStatus;
use App\Repository\AccountRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SavingsGoal> */
class SavingsGoalFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Family $family */
        $family = $options['family'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'savings_goal.form.name',
                'attr' => ['placeholder' => 'savings_goal.form.name_placeholder'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'savings_goal.form.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('account', EntityType::class, [
                'class' => Account::class,
                'query_builder' => fn (AccountRepository $repo) => $repo->createFamilyQueryBuilder($family),
                'label' => 'savings_goal.form.account',
                'choice_label' => 'name',
            ])
            ->add('targetAmount', MoneyType::class, [
                'currency' => $family->getCurrency(),
                'label' => 'savings_goal.form.target_amount',
                'required' => false,
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'savings_goal.form.target_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('status', EnumType::class, [
                'class' => SavingsGoalStatus::class,
                'label' => 'savings_goal.form.status',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SavingsGoal::class,
        ]);
        $resolver->setRequired('family');
        $resolver->setAllowedTypes('family', Family::class);
    }
}
