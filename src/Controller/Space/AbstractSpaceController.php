<?php

namespace App\Controller\Space;

use App\Entity\Calendar;
use App\Entity\Company;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Base des pages de l'espace entreprise (/espace).
 *
 * Chaque contrôleur porte son propre #[IsGranted('ROLE_COMPANY_MEMBER')] : PHP ne transmet pas les attributs
 * d'une classe à ses enfants.
 *
 * SpaceListener y ajoute deux règles : les dates s'affichent dans le fuseau de l'entreprise, et seule la page
 * d'accueil est ouverte tant que l'adresse e-mail n'est pas confirmée.
 */
abstract class AbstractSpaceController extends AbstractController
{
    protected function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    protected function company(): Company
    {
        return $this->user()->getCompany() ?? throw $this->createAccessDeniedException('Ce compte n\'est rattaché à aucune entreprise.');
    }

    /** @return list<Calendar> Les calendriers que l'utilisateur gère : tous pour le propriétaire, les siens pour un membre */
    protected function managedCalendars(): array
    {
        $user = $this->user();

        return array_values(array_filter(
            $this->company()->getCalendars()->toArray(),
            static fn (Calendar $calendar): bool => $user->manages($calendar),
        ));
    }
}
