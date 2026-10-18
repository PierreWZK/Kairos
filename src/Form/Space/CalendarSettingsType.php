<?php

namespace App\Form\Space;

use App\Entity\Calendar;
use App\Entity\Service;
use App\Twig\FormatExtension;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Les réglages d'un calendrier : présentation, prestations proposées, règles de réservation, formulaire client.
 *
 * @extends AbstractType<Calendar>
 */
final class CalendarSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Calendar $calendar */
        $calendar = $options['data'];
        $format = new FormatExtension();

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
                'help' => 'Une personne, une salle, un lieu… Affiché à vos clients.',
                'attr' => ['data-slug-source' => true],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Lien',
                'empty_data' => '',
                'help' => 'Minuscules, chiffres et tirets.',
                'attr' => ['data-slug-target' => true, 'spellcheck' => 'false'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Présentation',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Ouvert à la réservation',
                'required' => false,
            ])
            ->add('services', EntityType::class, [
                'label' => 'Prestations proposées',
                'class' => Service::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                // Seulement le catalogue de cette entreprise
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('s')
                    ->andWhere('s.company = :company')
                    ->setParameter('company', $calendar->getCompany())
                    ->orderBy('s.durationMinutes', 'ASC')
                    ->addOrderBy('s.name', 'ASC'),
                'choice_label' => static fn (Service $service): string => sprintf(
                    '%s · %s · %s%s',
                    $service->getName(),
                    $format->duration($service->getDurationMinutes()),
                    $format->price($service->getPriceCents()),
                    $service->isActive() ? '' : ' (désactivée)',
                ),
            ])
            ->add('slotStepMinutes', ChoiceType::class, [
                'label' => 'Un créneau commence toutes les',
                'choices' => array_combine(array_map(static fn (int $m): string => $m.' min', Calendar::SLOT_STEPS), Calendar::SLOT_STEPS),
            ])
            ->add('minNoticeHours', IntegerType::class, [
                'label' => 'Délai minimum avant un rendez-vous (heures)',
                'empty_data' => '0',
                'attr' => ['min' => 0, 'max' => 720],
            ])
            ->add('horizonWeeks', IntegerType::class, [
                'label' => 'Réservable jusqu\'à (semaines à l\'avance)',
                'empty_data' => '1',
                'attr' => ['min' => 1, 'max' => 52],
            ])
            ->add('cancelNoticeHours', IntegerType::class, [
                'label' => 'Annulation en ligne jusqu\'à (heures avant)',
                'empty_data' => '0',
                'attr' => ['min' => 0, 'max' => 720],
            ])
            ->add('requiresApproval', CheckboxType::class, [
                'label' => 'Valider chaque demande moi-même',
                'help' => 'Les réservations arrivent « en attente » : vous les acceptez ou les refusez, le client est prévenu par e-mail.',
                'required' => false,
            ])
            ->add('phoneRequired', CheckboxType::class, [
                'label' => 'Téléphone obligatoire',
                'required' => false,
            ])
            ->add('notesEnabled', CheckboxType::class, [
                'label' => 'Le client peut laisser une note',
                'required' => false,
            ])
            ->add('formMessage', TextareaType::class, [
                'label' => 'Message au-dessus du formulaire',
                'required' => false,
                'help' => 'Par exemple : « Merci d\'arriver 5 minutes en avance. »',
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Calendar::class]);
    }
}
