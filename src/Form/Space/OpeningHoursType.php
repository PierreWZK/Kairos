<?php

namespace App\Form\Space;

use App\Entity\Calendar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * La semaine type d'un calendrier : une liste de plages, qu'on ajoute et retire dans la page.
 *
 * @extends AbstractType<Calendar>
 */
final class OpeningHoursType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('openingHours', CollectionType::class, [
            'label' => false,
            'entry_type' => OpeningHourType::class,
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            // addOpeningHour() / removeOpeningHour() : la plage reçoit son calendrier, et une plage retirée est supprimée
            'by_reference' => false,
            'prototype' => true,
            'error_bubbling' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Calendar::class]);
    }
}
