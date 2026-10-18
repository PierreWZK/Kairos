<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * TranslatableInterface : EasyAdmin (et Twig avec |trans) affichent alors le libellé français, pas le nom du cas.
 */
enum BookingStatus: string implements TranslatableInterface
{
    /** Demande à valider par l'entreprise (calendrier en validation manuelle) */
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    /** Demande refusée par l'entreprise */
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Confirmed => 'Confirmée',
            self::Rejected => 'Refusée',
            self::Cancelled => 'Annulée',
        };
    }

    /** Un rendez-vous en attente bloque déjà son créneau : sinon deux demandes pourraient viser le même */
    public function blocksSlot(): bool
    {
        return self::Pending === $this || self::Confirmed === $this;
    }

    /** @return list<self> */
    public static function blocking(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status->blocksSlot()));
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
