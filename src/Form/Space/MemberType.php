<?php

namespace App\Form\Space;

use App\Entity\Calendar;
use App\Entity\User;
use App\Enum\CompanyRole;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un compte de l'équipe, vu par le propriétaire : son rôle et ses calendriers.
 * Le nom et l'adresse ne se saisissent qu'à l'invitation ; ensuite, chacun les gère dans « Mon compte ».
 *
 * @extends AbstractType<User>
 */
final class MemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $builder->getData();
        \assert($user instanceof User);

        if ($options['invite']) {
            $builder
                ->add('name', TextType::class, ['label' => 'Son nom', 'empty_data' => ''])
                ->add('email', EmailType::class, [
                    'label' => 'Son adresse e-mail',
                    'empty_data' => '',
                    'help' => 'L\'invitation y sera envoyée.',
                ]);
        }

        $builder
            ->add('companyRole', EnumType::class, [
                'label' => 'Rôle',
                'class' => CompanyRole::class,
                'expanded' => true,
                'choice_label' => static fn (CompanyRole $role): string => match ($role) {
                    CompanyRole::Member => 'Membre : gère les rendez-vous, horaires et fermetures de ses calendriers',
                    CompanyRole::Owner => 'Propriétaire : gère toute l\'entreprise, comme vous',
                },
            ])
            ->add('calendars', EntityType::class, [
                'label' => 'Ses calendriers',
                'class' => Calendar::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'help' => 'Un propriétaire gère tous les calendriers.',
                // Seulement les calendriers de cette entreprise
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('c')
                    ->andWhere('c.company = :company')
                    ->setParameter('company', $user->getCompany())
                    ->orderBy('c.name', 'ASC'),
                'choice_label' => 'name',
            ]);

        // Un propriétaire gère déjà tout : pas d'attribution qui resterait cachée s'il redevenait membre
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $member = $event->getData();
            if ($member instanceof User && $member->isOwner()) {
                foreach ($member->getCalendars()->toArray() as $calendar) {
                    $member->removeCalendar($calendar);
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'invite' => false,
        ]);
        $resolver->setAllowedTypes('invite', 'bool');
    }
}
