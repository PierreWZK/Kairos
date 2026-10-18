<?php

namespace App\Security\Voter;

use App\Entity\Calendar;
use App\Entity\Service;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui peut gérer quoi dans l'espace entreprise :
 * - un calendrier : le propriétaire de l'entreprise, ou un membre à qui il est attribué ;
 * - une prestation (le catalogue de l'entreprise) : le propriétaire seulement ;
 * - un compte de l'équipe : le propriétaire seulement, et jamais le sien (l'entreprise garde toujours un propriétaire).
 *
 * Un objet d'une autre entreprise est toujours refusé.
 *
 * @extends Voter<string, Calendar|Service|User>
 */
final class SpaceVoter extends Voter
{
    public const string MANAGE_CALENDAR = 'MANAGE_CALENDAR';
    public const string MANAGE_SERVICE = 'MANAGE_SERVICE';
    public const string MANAGE_MEMBER = 'MANAGE_MEMBER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::MANAGE_CALENDAR === $attribute && $subject instanceof Calendar)
            || (self::MANAGE_SERVICE === $attribute && $subject instanceof Service)
            || (self::MANAGE_MEMBER === $attribute && $subject instanceof User);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || null === $user->getCompany()) {
            return false;
        }

        return match (true) {
            $subject instanceof Calendar => $user->manages($subject),
            $subject instanceof Service => $user->isOwner() && $subject->getCompany() === $user->getCompany(),
            $subject instanceof User => $user->isOwner() && $subject->getCompany() === $user->getCompany() && $subject->getId() !== $user->getId(),
        };
    }
}
