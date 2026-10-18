<?php

namespace App\Scheduling;

use App\Entity\Booking;
use App\Entity\Calendar;
use App\Entity\Service;
use App\Entity\TimeOff;
use App\Repository\BookingRepository;
use App\Repository\OpeningHourRepository;
use App\Repository\TimeOffRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Calcule les créneaux d'une prestation sur un calendrier.
 *
 * Un créneau candidat commence toutes les N minutes (réglage du calendrier) dans une plage d'ouverture et doit y
 * tenir en entier. Il est ensuite libre, pris (chevauche un rendez-vous en attente ou confirmé) ou indisponible
 * (avant le délai minimum, au-delà de l'horizon de réservation, ou pendant une fermeture).
 *
 * Les créneaux sont construits en heure locale de l'entreprise (les horaires d'ouverture sont exprimés dans son
 * fuseau, changements d'heure compris) ; les comparaisons avec les rendez-vous, stockés en UTC, portent sur des
 * instants absolus.
 */
final class SlotFinder
{
    public function __construct(
        private readonly OpeningHourRepository $openingHours,
        private readonly TimeOffRepository $timeOffs,
        private readonly BookingRepository $bookings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Les 7 jours de la semaine qui contient $day (dans le fuseau du calendrier).
     *
     * @return list<DaySchedule>
     */
    public function week(Calendar $calendar, Service $service, \DateTimeImmutable $day): array
    {
        $timezone = $calendar->getDateTimeZone();
        $from = self::mondayOf($day->setTimezone($timezone));
        $to = $from->modify('+7 days');

        $hoursByDay = $this->openingHours->findAllByDay($calendar);
        $timeOffs = $this->timeOffs->findOverlapping($calendar, $from, $to);
        $bookings = $this->bookings->findBlockingOverlapping($calendar, $from, $to);

        $earliest = $this->earliestStart($calendar);
        $latest = $this->horizonEnd($calendar);
        $duration = sprintf('+%d minutes', $service->getDurationMinutes());
        $step = sprintf('+%d minutes', $calendar->getSlotStepMinutes());

        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $date = $from->modify("+$i days");
            $nextDay = $date->modify('+1 day');

            $fullDayOff = $this->findCovering($timeOffs, $date, $nextDay);
            if (null !== $fullDayOff) {
                $days[] = new DaySchedule($date, [], $fullDayOff->getReason() ?? 'Fermé');
                continue;
            }

            $slots = [];
            foreach ($hoursByDay[(int) $date->format('N')] ?? [] as $hour) {
                $opening = $this->atTime($date, $hour->getStartTime());
                $closing = $this->atTime($date, $hour->getEndTime());

                for ($start = $opening; ($end = $start->modify($duration)) <= $closing; $start = $start->modify($step)) {
                    $status = match (true) {
                        $start < $earliest, $start >= $latest => SlotStatus::Unavailable,
                        $this->overlapsAny($timeOffs, $start, $end) => SlotStatus::Unavailable,
                        $this->overlapsAny($bookings, $start, $end) => SlotStatus::Taken,
                        default => SlotStatus::Free,
                    };
                    $slots[] = new Slot($start, $end, $status);
                }
            }

            $days[] = new DaySchedule($date, $slots);
        }

        return $days;
    }

    /** Le créneau qui commence à $start est-il réservable pour cette prestation sur ce calendrier ? */
    public function isFree(Calendar $calendar, Service $service, \DateTimeImmutable $start): bool
    {
        foreach ($this->week($calendar, $service, $start) as $day) {
            foreach ($day->slots as $slot) {
                if ($slot->start == $start) {
                    return $slot->isFree();
                }
            }
        }

        return false;
    }

    /**
     * La première semaine affichable : celle du prochain jour encore ouvert après le délai minimum.
     * Un dimanche soir, on ouvre donc directement sur la semaine suivante plutôt que sur une semaine passée.
     */
    public function firstWeek(Calendar $calendar): \DateTimeImmutable
    {
        $earliest = $this->earliestStart($calendar);
        $hoursByDay = $this->openingHours->findAllByDay($calendar);

        for ($i = 0; $i < 7; ++$i) {
            $day = $earliest->setTime(0, 0)->modify("+$i days");
            foreach ($hoursByDay[(int) $day->format('N')] ?? [] as $hour) {
                if ($this->atTime($day, $hour->getEndTime()) > $earliest) {
                    return self::mondayOf($day);
                }
            }
        }

        return self::mondayOf($this->now($calendar));
    }

    /** Le lundi de la dernière semaine qui contient encore des jours réservables */
    public function lastWeek(Calendar $calendar): \DateTimeImmutable
    {
        return self::mondayOf($this->horizonEnd($calendar)->modify('-1 second'));
    }

    /** Ramène une date au lundi 00:00 de sa semaine, dans son propre fuseau */
    public static function mondayOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0)->modify(sprintf('-%d days', (int) $date->format('N') - 1));
    }

    /** Maintenant, dans le fuseau du calendrier */
    private function now(Calendar $calendar): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($calendar->getDateTimeZone());
    }

    private function earliestStart(Calendar $calendar): \DateTimeImmutable
    {
        return $this->now($calendar)->modify(sprintf('+%d hours', $calendar->getMinNoticeHours()));
    }

    /** Fin (exclue) de la période réservable : minuit (heure locale), dans N semaines */
    private function horizonEnd(Calendar $calendar): \DateTimeImmutable
    {
        return $this->now($calendar)->setTime(0, 0)->modify(sprintf('+%d weeks', $calendar->getHorizonWeeks()));
    }

    /** Une heure de plage d'ouverture (stockée sans date) appliquée à un jour donné, dans le fuseau de ce jour */
    private function atTime(\DateTimeImmutable $date, \DateTimeImmutable $time): \DateTimeImmutable
    {
        return $date->setTime((int) $time->format('H'), (int) $time->format('i'));
    }

    /** @param list<TimeOff> $timeOffs */
    private function findCovering(array $timeOffs, \DateTimeImmutable $from, \DateTimeImmutable $to): ?TimeOff
    {
        foreach ($timeOffs as $timeOff) {
            if ($timeOff->getStartsAt() <= $from && $timeOff->getEndsAt() >= $to) {
                return $timeOff;
            }
        }

        return null;
    }

    /** @param list<TimeOff|Booking> $periods */
    private function overlapsAny(array $periods, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        foreach ($periods as $period) {
            if ($period->getStartsAt() < $end && $period->getEndsAt() > $start) {
                return true;
            }
        }

        return false;
    }
}
