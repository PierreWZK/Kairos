<?php

namespace App\Notification;

use App\Entity\Booking;

/**
 * Fichier .ics (iCalendar, RFC 5545) joint à la confirmation : le client ajoute le rendez-vous à son agenda en un clic.
 */
final class CalendarInvite
{
    public static function forBooking(Booking $booking, string $manageUrl): string
    {
        $utc = new \DateTimeZone('UTC');
        $format = static fn (\DateTimeImmutable $date): string => $date->setTimezone($utc)->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Kairos//Reservation//FR',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:booking-'.$booking->getId().'@kairos',
            'DTSTAMP:'.$format(new \DateTimeImmutable()),
            'DTSTART:'.$format($booking->getStartsAt()),
            'DTEND:'.$format($booking->getEndsAt()),
            'SUMMARY:'.self::escape($booking->getService()->getName()),
            'DESCRIPTION:'.self::escape('Gérer ou annuler le rendez-vous : '.$manageUrl),
            'URL:'.$manageUrl,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    private static function escape(string $text): string
    {
        return addcslashes(str_replace(["\r\n", "\n"], '\n', $text), ',;\\');
    }
}
