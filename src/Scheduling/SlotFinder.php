<?php

namespace App\Scheduling;

use App\Entity\Booking;
use App\Entity\Service;
use App\Entity\TimeOff;
use App\Repository\BookingRepository;
use App\Repository\OpeningHourRepository;
use App\Repository\TimeOffRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Calcule les créneaux d'une prestation.
 *
 * Un créneau candidat commence toutes les N minutes dans une plage d'ouverture et doit y tenir en entier.
 * Il est ensuite libre, pris (chevauche un rendez-vous confirmé) ou indisponible (avant le délai minimum,
 * au-delà de l'horizon de réservation, ou pendant une fermeture).
 */
final class SlotFinder
{
    public function __construct(
        private readonly OpeningHourRepository $openingHours,
        private readonly TimeOffRepository $timeOffs,
        private readonly BookingRepository $bookings,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.booking.slot_step_minutes')]
        private readonly int $stepMinutes,
        #[Autowire(param: 'app.booking.min_notice_hours')]
        private readonly int $minNoticeHours,
        #[Autowire(param: 'app.booking.horizon_weeks')]
        private readonly int $horizonWeeks,
    ) {
    }

    /**
     * Les 7 jours de la semaine qui commence le lundi $monday.
     *
     * @return list<DaySchedule>
     */
    public function week(Service $service, \DateTimeImmutable $monday): array
    {
        $from = self::mondayOf($monday);
        $to = $from->modify('+7 days');

        $hoursByDay = $this->openingHours->findAllByDay();
        $timeOffs = $this->timeOffs->findOverlapping($from, $to);
        $bookings = $this->bookings->findConfirmedOverlapping($from, $to);

        $earliest = $this->now()->modify(sprintf('+%d hours', $this->minNoticeHours));
        $latest = $this->horizonEnd();
        $duration = sprintf('+%d minutes', $service->getDurationMinutes());
        $step = sprintf('+%d minutes', $this->stepMinutes);

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

    /** Le créneau qui commence à $start est-il réservable pour cette prestation ? */
    public function isFree(Service $service, \DateTimeImmutable $start): bool
    {
        foreach ($this->week($service, $start) as $day) {
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
    public function firstWeek(): \DateTimeImmutable
    {
        $earliest = $this->now()->modify(sprintf('+%d hours', $this->minNoticeHours));
        $hoursByDay = $this->openingHours->findAllByDay();

        for ($i = 0; $i < 7; ++$i) {
            $day = $earliest->setTime(0, 0)->modify("+$i days");
            foreach ($hoursByDay[(int) $day->format('N')] ?? [] as $hour) {
                if ($this->atTime($day, $hour->getEndTime()) > $earliest) {
                    return self::mondayOf($day);
                }
            }
        }

        return self::mondayOf($this->now());
    }

    /** Le lundi de la dernière semaine qui contient encore des jours réservables */
    public function lastWeek(): \DateTimeImmutable
    {
        return self::mondayOf($this->horizonEnd()->modify('-1 second'));
    }

    /** Ramène une date au lundi 00:00 de sa semaine */
    public static function mondayOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0)->modify(sprintf('-%d days', (int) $date->format('N') - 1));
    }

    /** Fin (exclue) de la période réservable : minuit, dans N semaines */
    private function horizonEnd(): \DateTimeImmutable
    {
        return $this->now()->setTime(0, 0)->modify(sprintf('+%d weeks', $this->horizonWeeks));
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** Une heure de plage d'ouverture (stockée sans date) appliquée à un jour donné */
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
