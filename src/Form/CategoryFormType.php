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
                'label' => 'Category Name',
                'constraints' => [
                    new NotBlank(['message' => 'Please enter a category name.']),
                    new Length(['max' => 100]),
                ],
                'attr' => ['placeholder' => 'e.g. Groceries'],
            ])
            ->add('type', EnumType::class, [
                'class' => CategoryType::class,
                'label' => 'Category Type',
            ])
            ->add('icon', TextType::class, [
                'label'    => 'Icon (emoji)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 🛒', 'maxlength' => 10],
            ])
            ->add('color', ColorType::class, [
                'label'    => 'Color',
                'required' => false,
            ])
            ->add('parent', EntityType::class, [
                'class'         => Category::class,
                'query_builder' => fn ($repo) => $repo->createFamilyQueryBuilder($family),
                'label'         => 'Parent Category',
                'required'      => false,
                'placeholder'   => '— none —',
                'choice_label'  => 'name',
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
            'data_class' => Category::class,
        ]);
        $resolver->setRequired('family');
        $resolver->setAllowedTypes('family', Family::class);
    }
}
