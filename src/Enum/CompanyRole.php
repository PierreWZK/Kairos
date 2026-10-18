<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le rôle d'un compte dans son entreprise.
 */
enum CompanyRole: string implements TranslatableInterface
{
    /** Gère toute l'entreprise : calendriers, prestations, équipe, réglages */
    case Owner = 'owner';
    /** Gère uniquement les calendriers qui lui sont attribués */
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propriétaire',
            self::Member => 'Membre',
        };
    }

    /** Le rôle Symfony correspondant, utilisé par la sécurité */
    public function securityRole(): string
    {
        return match ($this) {
            self::Owner => 'ROLE_COMPANY_OWNER',
            self::Member => 'ROLE_COMPANY_MEMBER',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
