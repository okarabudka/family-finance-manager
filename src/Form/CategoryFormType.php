<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\Family;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<\App\Entity\Category> */
class CategoryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Family $family */
        $family = $options['family'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'category.form.name',
                'constraints' => [
                    new NotBlank(message: 'category.name.not_blank'),
                    new Length(max: 100),
                ],
                'attr' => ['placeholder' => 'category.form.name_placeholder'],
            ])
            ->add('type', EnumType::class, [
                'class' => CategoryType::class,
                'label' => 'category.form.type',
            ])
            ->add('icon', TextType::class, [
                'label'    => 'category.form.icon',
                'required' => false,
                'attr'     => ['placeholder' => 'category.form.icon_placeholder', 'maxlength' => 10],
            ])
            ->add('color', ColorType::class, [
                'label'    => 'category.form.color',
                'required' => false,
            ])
            ->add('parent', EntityType::class, [
                'class'         => Category::class,
                'query_builder' => fn ($repo) => $repo->createFamilyQueryBuilder($family),
                'label'         => 'category.form.parent',
                'required'      => false,
                'placeholder'   => 'common.none',
                'choice_label'  => 'name',
            ])
            ->add('sortOrder', IntegerType::class, [
                'label'    => 'common.sort_order',
                'required' => false,
                'data'     => 0,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
        $resolver->setRequired('family');
        $resolver->setAllowedTypes('family', Family::class);
    }
}
