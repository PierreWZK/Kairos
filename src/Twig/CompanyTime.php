<?php

namespace App\Twig;

use App\Entity\Calendar;
use Twig\Attribute\AsTwigFilter;

/**
 * Une date du super-admin à l'heure locale de l'entreprise concernée : un rendez-vous à Toronto s'y lit
 * « 9:00 (heure de Toronto) », pas à l'heure de Paris. L'heure de Paris, celle de Kairos, n'est pas précisée.
 */
final class CompanyTime
{
    private const string PLATFORM_TIMEZONE = 'Europe/Paris';

    #[AsTwigFilter('company_time')]
    public static function format(?\DateTimeInterface $instant, ?Calendar $calendar, string $pattern = 'EEE d MMM y, HH:mm'): string
    {
        if (null === $instant) {
            return '';
        }

        $timezone = $calendar?->getDateTimeZone() ?? new \DateTimeZone(self::PLATFORM_TIMEZONE);
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timezone, null, $pattern);
        $formatted = ucfirst((string) $formatter->format($instant));

        if (self::PLATFORM_TIMEZONE === $timezone->getName()) {
            return $formatted;
        }

        $city = str_replace('_', ' ', substr($timezone->getName(), (int) strrpos($timezone->getName(), '/') + 1));

        return sprintf('%s (heure de %s)', $formatted, $city);
    }

    /** « Salon Lumière · Julie » : deux entreprises peuvent avoir un calendrier du même nom */
    public static function calendarLabel(?Calendar $calendar): string
    {
        return null === $calendar ? '' : sprintf('%s · %s', $calendar->getCompany()?->getName(), $calendar->getName());
    }
}
