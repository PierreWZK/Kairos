<?php

namespace App\Form\Space;

use App\Entity\Service;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<Service> */
final class ServiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom', 'empty_data' => ''])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('durationMinutes', IntegerType::class, [
                'label' => 'Durée (minutes)',
                'empty_data' => '0',
                'attr' => ['min' => 5, 'max' => 480, 'step' => 5],
            ])
            // Saisi en euros, enregistré en centimes
            ->add('priceCents', MoneyType::class, [
                'label' => 'Prix',
                'currency' => 'EUR',
                'divisor' => 100,
                'input' => 'integer',
                'empty_data' => '0',
                'help' => '0 pour une prestation gratuite.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Réservable',
                'help' => 'Une prestation désactivée disparaît des pages de réservation, sans toucher aux rendez-vous déjà pris.',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Service::class]);
    }
}
