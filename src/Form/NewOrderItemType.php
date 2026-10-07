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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
        $productTypes = $this->productTypeRepository->findActiveOrdered();
        $units = $this->unitRepository->findActiveOrdered();
        $data = $options['data'] ?? null;
        if (true === $options['include_inactive'] && $data instanceof NewOrderItemData) {
            if (null !== $data->productType && !$data->productType->isActive()) {
                array_unshift($productTypes, $data->productType);
            }
            if (null !== $data->unit && !$data->unit->isActive()) {
                array_unshift($units, $data->unit);
            }
        }

        $builder
            ->add('id', HiddenType::class, [
                'required' => false,
            ])
            ->add('productType', EntityType::class, [
                'class' => ProductType::class,
                'choices' => $productTypes,
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
                'choices' => $units,
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
            'include_inactive' => false,
        ]);
        $resolver->setAllowedTypes('include_inactive', 'bool');
    }
}
