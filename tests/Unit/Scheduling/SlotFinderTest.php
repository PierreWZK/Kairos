<?php

namespace App\Tests\Unit\Scheduling;

use App\Entity\Booking;
use App\Entity\OpeningHour;
use App\Entity\Service;
use App\Entity\TimeOff;
use App\Repository\BookingRepository;
use App\Repository\OpeningHourRepository;
use App\Repository\TimeOffRepository;
use App\Scheduling\DaySchedule;
use App\Scheduling\SlotFinder;
use App\Scheduling\SlotStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Le calcul des créneaux, sans base de données : les repositories sont remplacés par des stubs
 * et l'heure est figée au lundi 19 octobre 2026, 08:00.
 */
final class SlotFinderTest extends TestCase
{
    private const string NOW = '2026-10-19 08:00';
    private const string NEXT_MONDAY = '2026-10-26';

    public function testSlotsStartEveryStepAndFitEntirelyInTheOpeningHours(): void
    {
        $finder = $this->finder(hours: [[1, '09:00', '12:00']]);

        $days = $finder->week($this->service(90), new \DateTimeImmutable(self::NEXT_MONDAY));

        // Une séance de 1 h 30 commence au plus tard à 10:30 pour finir à 12:00
        self::assertSame(['09:00', '09:30', '10:00', '10:30'], $this->startTimes($days[0]));
        self::assertCount(7, $days);
        self::assertTrue($days[1]->isClosed(), 'Mardi n\'a pas d\'horaires : la journée est fermée.');
        self::assertNull($days[1]->closedReason);
    }

    public function testSlotsOverlappingAConfirmedBookingAreTaken(): void
    {
        $service = $this->service(60);
        $finder = $this->finder(
            hours: [[1, '09:00', '12:00']],
            bookings: [$this->booking($service, self::NEXT_MONDAY.' 10:00')],
        );

        $days = $finder->week($service, new \DateTimeImmutable(self::NEXT_MONDAY));

        self::assertSame([
            '09:00' => SlotStatus::Free,
            '09:30' => SlotStatus::Taken, // 09:30-10:30 chevauche 10:00-11:00
            '10:00' => SlotStatus::Taken,
            '10:30' => SlotStatus::Taken,
            '11:00' => SlotStatus::Free,
        ], $this->statuses($days[0]));
    }

    public function testSlotsBeforeTheMinimumNoticeAreUnavailable(): void
    {
        // Il est 08:00 et il faut réserver 2 h à l'avance : rien avant 10:00 aujourd'hui
        $finder = $this->finder(hours: [[1, '09:00', '12:00']]);

        $days = $finder->week($this->service(60), new \DateTimeImmutable('2026-10-19'));

        self::assertSame([
            '09:00' => SlotStatus::Unavailable,
            '09:30' => SlotStatus::Unavailable,
            '10:00' => SlotStatus::Free,
            '10:30' => SlotStatus::Free,
            '11:00' => SlotStatus::Free,
        ], $this->statuses($days[0]));
    }

    public function testSlotsBeyondTheHorizonAreUnavailable(): void
    {
        // Horizon de 4 semaines : réservable jusqu'au dimanche 15 novembre inclus
        $finder = $this->finder(hours: [[1, '09:00', '12:00']]);
        $service = $this->service(60);

        $lastBookableMonday = $finder->week($service, new \DateTimeImmutable('2026-11-09'))[0];
        $tooFarMonday = $finder->week($service, new \DateTimeImmutable('2026-11-16'))[0];

        self::assertTrue($lastBookableMonday->hasFreeSlot());
        self::assertFalse($tooFarMonday->hasFreeSlot());
        self::assertSame([SlotStatus::Unavailable], array_values(array_unique($this->statuses($tooFarMonday), \SORT_REGULAR)));
        self::assertEquals(new \DateTimeImmutable('2026-11-09'), $finder->lastWeek());
    }

    public function testATimeOffCoveringTheWholeDayClosesIt(): void
    {
        $finder = $this->finder(
            hours: [[1, '09:00', '12:00']],
            timeOffs: [$this->timeOff(self::NEXT_MONDAY.' 00:00', '2026-10-27 00:00', 'Congés')],
        );

        $monday = $finder->week($this->service(60), new \DateTimeImmutable(self::NEXT_MONDAY))[0];

        self::assertTrue($monday->isClosed());
        self::assertSame('Congés', $monday->closedReason);
    }

    public function testAPartialTimeOffOnlyBlocksTheSlotsItOverlaps(): void
    {
        $finder = $this->finder(
            hours: [[1, '09:00', '12:00']],
            timeOffs: [$this->timeOff(self::NEXT_MONDAY.' 10:00', self::NEXT_MONDAY.' 11:00', 'Rendez-vous médical')],
        );

        $monday = $finder->week($this->service(30), new \DateTimeImmutable(self::NEXT_MONDAY))[0];

        self::assertFalse($monday->isClosed());
        self::assertSame(SlotStatus::Free, $this->statuses($monday)['09:30']);
        self::assertSame(SlotStatus::Unavailable, $this->statuses($monday)['10:00']);
        self::assertSame(SlotStatus::Unavailable, $this->statuses($monday)['10:30']);
        self::assertSame(SlotStatus::Free, $this->statuses($monday)['11:00']);
    }

    public function testIsFreeOnlyAcceptsFreeSlotsOnTheGrid(): void
    {
        $finder = $this->finder(hours: [[1, '09:00', '12:00']]);
        $service = $this->service(60);

        self::assertTrue($finder->isFree($service, new \DateTimeImmutable(self::NEXT_MONDAY.' 10:00')));
        self::assertFalse($finder->isFree($service, new \DateTimeImmutable(self::NEXT_MONDAY.' 10:15')), 'Hors de la grille de 30 min');
        self::assertFalse($finder->isFree($service, new \DateTimeImmutable(self::NEXT_MONDAY.' 11:30')), 'Finirait après la fermeture');
        self::assertFalse($finder->isFree($service, new \DateTimeImmutable(self::NEXT_MONDAY.' 14:00')), 'Hors des horaires');
        self::assertFalse($finder->isFree($service, new \DateTimeImmutable('2026-10-19 09:00')), 'Avant le délai minimum');
    }

    public function testTheFirstWeekSkipsAWeekThatIsAlreadyOver(): void
    {
        $hours = [[1, '09:00', '12:00'], [6, '09:00', '12:00']];

        // Dimanche soir : plus rien cette semaine, l'agenda ouvre sur la suivante
        self::assertEquals(new \DateTimeImmutable('2026-10-26'), $this->finder($hours, now: '2026-10-25 20:00')->firstWeek());
        // Samedi 08:00 : il reste la matinée du samedi (après 10:00), on reste sur la semaine en cours
        self::assertEquals(new \DateTimeImmutable('2026-10-19'), $this->finder($hours, now: '2026-10-24 08:00')->firstWeek());
        // Samedi 11:00 : le délai de 2 h dépasse la fermeture de midi, on passe à la semaine suivante
        self::assertEquals(new \DateTimeImmutable('2026-10-26'), $this->finder($hours, now: '2026-10-24 11:00')->firstWeek());
    }

    public function testMondayOfReturnsMidnightOnTheMondayOfTheWeek(): void
    {
        self::assertEquals(new \DateTimeImmutable('2026-10-19 00:00'), SlotFinder::mondayOf(new \DateTimeImmutable('2026-10-25 23:59')));
        self::assertEquals(new \DateTimeImmutable('2026-10-19 00:00'), SlotFinder::mondayOf(new \DateTimeImmutable('2026-10-19 08:00')));
    }

    /**
     * @param list<array{int, string, string}> $hours    [jour ISO, début, fin]
     * @param list<TimeOff>                    $timeOffs
     * @param list<Booking>                    $bookings
     */
    private function finder(array $hours, array $timeOffs = [], array $bookings = [], string $now = self::NOW): SlotFinder
    {
        $byDay = [];
        foreach ($hours as [$day, $start, $end]) {
            $byDay[$day][] = (new OpeningHour())
                ->setDayOfWeek($day)
                ->setStartTime(new \DateTimeImmutable($start))
                ->setEndTime(new \DateTimeImmutable($end));
        }

        $openingHours = $this->createStub(OpeningHourRepository::class);
        $openingHours->method('findAllByDay')->willReturn($byDay);
        $timeOffRepository = $this->createStub(TimeOffRepository::class);
        $timeOffRepository->method('findOverlapping')->willReturn($timeOffs);
        $bookingRepository = $this->createStub(BookingRepository::class);
        $bookingRepository->method('findConfirmedOverlapping')->willReturn($bookings);

        return new SlotFinder(
            $openingHours,
            $timeOffRepository,
            $bookingRepository,
            // MockClock interprète la date en UTC par défaut : on précise qu'il s'agit de l'heure de Paris
            new MockClock($now, 'Europe/Paris'),
            stepMinutes: 30,
            minNoticeHours: 2,
            horizonWeeks: 4,
        );
    }

    private function service(int $minutes): Service
    {
        return (new Service())->setName("Séance $minutes min")->setDurationMinutes($minutes);
    }

    private function booking(Service $service, string $start): Booking
    {
        return (new Booking())->setService($service)->setStartsAt(new \DateTimeImmutable($start));
    }

    private function timeOff(string $start, string $end, string $reason): TimeOff
    {
        return (new TimeOff())
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setReason($reason);
    }

    /** @return list<string> */
    private function startTimes(DaySchedule $day): array
    {
        return array_map(static fn ($slot): string => $slot->start->format('H:i'), $day->slots);
    }

    /** @return array<string, SlotStatus> */
    private function statuses(DaySchedule $day): array
    {
        $statuses = [];
        foreach ($day->slots as $slot) {
            $statuses[$slot->start->format('H:i')] = $slot->status;
        }

        return $statuses;
    }
}
