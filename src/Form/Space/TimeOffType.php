<?php

namespace App\Form\Space;

use App\Entity\TimeOff;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une fermeture (congés, formation…), saisie à l'heure de l'entreprise et enregistrée en UTC.
 *
 * @extends AbstractType<TimeOff>
 */
final class TimeOffType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $dates = [
            'input' => 'datetime_immutable',
            'widget' => 'single_text',
            'model_timezone' => 'UTC',
            'view_timezone' => $options['timezone'],
        ];

        $builder
            ->add('startsAt', DateTimeType::class, ['label' => 'Du'] + $dates)
            ->add('endsAt', DateTimeType::class, ['label' => 'Au'] + $dates)
            ->add('reason', TextType::class, [
                'label' => 'Motif',
                'required' => false,
                'help' => 'Affiché à vos clients dans l\'agenda (ex. « Congés »).',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TimeOff::class]);
        $resolver->setRequired('timezone');
        $resolver->setAllowedTypes('timezone', 'string');
    }
}
