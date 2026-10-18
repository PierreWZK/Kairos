<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

final class FormatExtension
{
    /** 30 → « 30 min », 60 → « 1 h », 90 → « 1 h 30 » */
    #[AsTwigFilter('duration')]
    public function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            0 === $hours => sprintf('%d min', $rest),
            0 === $rest => sprintf('%d h', $hours),
            default => sprintf('%d h %02d', $hours, $rest),
        };
    }

    /** 6000 → « 60 € », 8550 → « 85,50 € », 0 → « Gratuit » */
    #[AsTwigFilter('price')]
    public function price(int $cents): string
    {
        if (0 === $cents) {
            return 'Gratuit';
        }

        $decimals = 0 === $cents % 100 ? 0 : 2;

        return number_format($cents / 100, $decimals, ',', "\u{202F}").' €';
    }
}
