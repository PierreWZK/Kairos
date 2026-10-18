<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * TranslatableInterface : EasyAdmin (et Twig avec |trans) affichent alors le libellé français, pas le nom du cas.
 */
enum BookingStatus: string implements TranslatableInterface
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmée',
            self::Cancelled => 'Annulée',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
