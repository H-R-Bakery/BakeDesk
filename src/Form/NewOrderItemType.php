<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProductType;
use App\Entity\Unit;
use App\Form\Model\NewOrderItemData;
use App\Repository\ProductTypeRepository;
use App\Repository\UnitRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NewOrderItemType extends AbstractType
{
    public function __construct(
        private ProductTypeRepository $productTypeRepository,
        private UnitRepository $unitRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('productType', EntityType::class, [
                'class' => ProductType::class,
                'choices' => $this->productTypeRepository->findActiveOrdered(),
                'choice_label' => 'name',
                'placeholder' => 'Choose a type',
                'label' => 'Type',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('quantity', TextType::class, [
                'label' => 'Quantity',
                'attr' => [
                    'class' => 'form-control',
                    'inputmode' => 'decimal',
                    'pattern' => '[0-9]+([.][0-9]{1,2})?',
                ],
            ])
            ->add('unit', EntityType::class, [
                'class' => Unit::class,
                'choices' => $this->unitRepository->findActiveOrdered(),
                'choice_label' => 'name',
                'placeholder' => 'Choose a unit',
                'label' => 'Unit',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description',
                'empty_data' => '',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Glazed, chocolate iced, or other details',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NewOrderItemData::class,
        ]);
    }
}
