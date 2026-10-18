<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse la connexion des membres d'une entreprise suspendue par le super-admin,
 * et celle d'un invité qui n'a pas encore choisi son mot de passe.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
        if (true === $user->getCompany()?->isSuspended()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est suspendu. Contactez Kairos pour en savoir plus.');
        }
        if ($user->isInvitationPending()) {
            throw new CustomUserMessageAccountStatusException('Choisissez d\'abord votre mot de passe avec le lien de votre invitation, reçu par e-mail.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
