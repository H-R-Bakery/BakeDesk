<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Employee;
use App\Form\Model\NewOrderData;
use App\Repository\EmployeeRepository;
use Misd\PhoneNumberBundle\Form\Type\PhoneNumberType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

final class NewOrderType extends AbstractType
{
    public function __construct(
        private EmployeeRepository $employeeRepository,
        #[Autowire('%bakery_timezone%')]
        private string $bakeryTimezone,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $employees = $this->employeeRepository->findActiveOrdered();
        if (true === $options['include_inactive'] && $options['data'] instanceof NewOrderData) {
            if (null !== $options['data']->employee && !$options['data']->employee->isActive()) {
                array_unshift($employees, $options['data']->employee);
            }
        }

        $builder
            ->add('customerName', TextType::class, [
                'label' => 'Customer name',
                'empty_data' => '',
                'attr' => [
                    'class' => 'form-control',
                    'autocomplete' => 'off',
                    'data-customer-autocomplete-target' => 'name',
                ],
            ])
            ->add('customerId', HiddenType::class, [
                'required' => false,
                'attr' => ['data-customer-autocomplete-target' => 'customer'],
            ])
            ->add('customerPhone', PhoneNumberType::class, [
                'label' => 'Customer phone',
                'default_region' => 'US',
                'number_type' => PhoneNumberType::NUMBER_TYPE_TEL,
                'attr' => [
                    'class' => 'form-control',
                    'autocomplete' => 'off',
                    'data-customer-autocomplete-target' => 'phone',
                ],
            ])
            ->add('employee', EntityType::class, [
                'class' => Employee::class,
                'choices' => $employees,
                'choice_label' => 'name',
                'expanded' => true,
                'multiple' => false,
                'label' => 'Employee',
                'choice_attr' => static fn (Employee $employee): array => [
                    'data-employee-preference-target' => 'employee',
                ],
            ])
            ->add('pickupDate', DateType::class, [
                'label' => 'Pickup date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => $this->bakeryTimezone,
                'view_timezone' => $this->bakeryTimezone,
                'html5' => true,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('pickupTime', TimeType::class, [
                'label' => 'Pickup time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => $this->bakeryTimezone,
                'view_timezone' => $this->bakeryTimezone,
                'with_seconds' => false,
                'html5' => true,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('paid', CheckboxType::class, [
                'label' => 'Paid',
                'required' => false,
                'label_attr' => ['class' => 'checkbox-switch'],
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Optional notes for the bakery team',
                ],
            ])
            ->add('items', CollectionType::class, [
                'entry_type' => NewOrderItemType::class,
                'entry_options' => [
                    'include_inactive' => $options['include_inactive'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'label' => false,
                'constraints' => [new Count(min: 1, minMessage: 'Add at least one order item.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NewOrderData::class,
            'include_inactive' => false,
        ]);
        $resolver->setAllowedTypes('include_inactive', 'bool');
    }
}
