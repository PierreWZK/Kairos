<?php

namespace App\Form\Space;

use App\Entity\OpeningHour;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une plage d'ouverture : un jour, une heure de début et de fin (heure locale de l'entreprise).
 *
 * @extends AbstractType<OpeningHour>
 */
final class OpeningHourType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dayOfWeek', ChoiceType::class, [
                'label' => 'Jour',
                'choices' => array_flip(OpeningHour::DAY_NAMES),
            ])
            ->add('startTime', TimeType::class, [
                'label' => 'De',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
            ])
            ->add('endTime', TimeType::class, [
                'label' => 'À',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OpeningHour::class]);
    }
}
