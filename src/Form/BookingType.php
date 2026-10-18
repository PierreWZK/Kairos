<?php

namespace App\Form;

use App\Entity\Booking;
use App\Entity\Calendar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Les coordonnées du client. La prestation et l'horaire sont fixés par le contrôleur, pas par le formulaire.
 * Les champs suivent les options du calendrier (téléphone obligatoire, note autorisée).
 */
final class BookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Calendar $calendar */
        $calendar = $options['calendar'];

        $builder
            ->add('customerName', TextType::class, [
                'label' => 'Nom et prénom',
                // Un champ vide doit arriver en chaîne vide (pas null) pour que le Validator le signale
                'empty_data' => '',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('customerEmail', EmailType::class, [
                'label' => 'E-mail',
                'empty_data' => '',
                'help' => 'Le récapitulatif et le lien d\'annulation seront envoyés à cette adresse.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('customerPhone', TelType::class, [
                'label' => 'Téléphone',
                'required' => $calendar->isPhoneRequired(),
                'constraints' => $calendar->isPhoneRequired() ? [new NotBlank(message: 'Indiquez un numéro de téléphone.')] : [],
                'attr' => ['autocomplete' => 'tel'],
            ]);

        if ($calendar->areNotesEnabled()) {
            $builder->add('note', TextareaType::class, [
                'label' => 'Un mot pour préparer le rendez-vous',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Booking::class,
        ]);
        $resolver->setRequired('calendar');
        $resolver->setAllowedTypes('calendar', Calendar::class);
    }
}
