<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Family;
use App\Entity\TransactionType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<mixed> */
class TransactionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Family $family */
        $family = $options['family'];

        $builder
            ->add('type', EnumType::class, [
                'class' => TransactionType::class,
                'label' => 'Тип',
            ])
            ->add('amount', MoneyType::class, [
                'currency' => $family->getCurrency(),
                'label' => 'Сумма',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'query_builder' => fn($repo) => $repo->createFamilyQueryBuilder($family),
                'label' => 'Категория',
                'required' => false,
            ])
            ->add('account', EntityType::class, [
                'class' => Account::class,
                'query_builder' => fn($repo) => $repo->createFamilyQueryBuilder($family),
                'label' => 'Счёт',
            ])
            ->add('targetAccount', EntityType::class, [
                'class' => Account::class,
                'query_builder' => fn($repo) => $repo->createFamilyQueryBuilder($family),
                'label' => 'Счёт получателя',
                'required' => false,
            ])
            ->add('executedAt', DateType::class, [
                'label' => 'Дата',
                'data' => new \DateTime(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('family');
        $resolver->setAllowedTypes('family', Family::class);
    }
}
