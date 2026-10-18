<?php

namespace App\Tests\Unit\Scheduling;

use App\Entity\Booking;
use App\Entity\Calendar;
use App\Entity\Company;
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
 * et l'heure est figée au lundi 19 octobre 2026, 08:00 heure de Paris.
 *
 * Les règles viennent du calendrier (pas de 30 min, 2 h de délai, 4 semaines d'horizon par défaut) et les
 * horaires sont en heure locale de l'entreprise.
 */
final class SlotFinderTest extends TestCase
{
    private const string NOW = '2026-10-19 08:00';
    private const string NEXT_MONDAY = '2026-10-26';

    public function testSlotsStartEveryStepAndFitEntirelyInTheOpeningHours(): void
    {
        [$finder, $calendar] = $this->finder(hours: [[1, '09:00', '12:00']]);

        $days = $finder->week($calendar, $this->service(90), self::paris(self::NEXT_MONDAY));

        // Une séance de 1 h 30 commence au plus tard à 10:30 pour finir à 12:00
        self::assertSame(['09:00', '09:30', '10:00', '10:30'], $this->startTimes($days[0]));
        self::assertCount(7, $days);
        self::assertTrue($days[1]->isClosed(), 'Mardi n\'a pas d\'horaires : la journée est fermée.');
        self::assertNull($days[1]->closedReason);
    }

    public function testTheSlotStepComesFromTheCalendar(): void
    {
        [$finder, $calendar] = $this->finder(hours: [[1, '09:00', '10:00']], configure: static fn (Calendar $c) => $c->setSlotStepMinutes(15));

        $monday = $finder->week($calendar, $this->service(30), self::paris(self::NEXT_MONDAY))[0];

        self::assertSame(['09:00', '09:15', '09:30'], $this->startTimes($monday));
    }

    public function testSlotsOverlappingABlockingBookingAreTaken(): void
    {
        $service = $this->service(60);
        [$finder, $calendar] = $this->finder(
            hours: [[1, '09:00', '12:00']],
            bookings: [$this->booking($service, self::NEXT_MONDAY.' 10:00')],
        );

        $days = $finder->week($calendar, $service, self::paris(self::NEXT_MONDAY));

        self::assertSame([
            '09:00' => SlotStatus::Free,
            '09:30' => SlotStatus::Taken, // 09:30-10:30 chevauche 10:00-11:00
            '10:00' => SlotStatus::Taken,
            '10:30' => SlotStatus::Taken,
            '11:00' => SlotStatus::Free,
        ], $this->statuses($days[0]));
    }

    public function testTheMinimumNoticeComesFromTheCalendar(): void
    {
        // Il est 08:00 : avec 2 h de délai rien avant 10:00, avec 0 h tout ce qui n'est pas passé
        [$finder, $calendar] = $this->finder(hours: [[1, '09:00', '12:00']]);
        $today = $finder->week($calendar, $this->service(60), self::paris('2026-10-19'))[0];

        self::assertSame(SlotStatus::Unavailable, $this->statuses($today)['09:30']);
        self::assertSame(SlotStatus::Free, $this->statuses($today)['10:00']);

        [$finder, $calendar] = $this->finder(hours: [[1, '09:00', '12:00']], configure: static fn (Calendar $c) => $c->setMinNoticeHours(0));
        $today = $finder->week($calendar, $this->service(60), self::paris('2026-10-19'))[0];

        self::assertSame(SlotStatus::Free, $this->statuses($today)['09:00']);
    }

    public function testSlotsBeyondTheHorizonAreUnavailable(): void
    {
        // Horizon de 4 semaines : réservable jusqu'au dimanche 15 novembre inclus
        [$finder, $calendar] = $this->finder(hours: [[1, '09:00', '12:00']]);
        $service = $this->service(60);

        $lastBookableMonday = $finder->week($calendar, $service, self::paris('2026-11-09'))[0];
        $tooFarMonday = $finder->week($calendar, $service, self::paris('2026-11-16'))[0];

        self::assertTrue($lastBookableMonday->hasFreeSlot());
        self::assertFalse($tooFarMonday->hasFreeSlot());
        self::assertEquals(self::paris('2026-11-09'), $finder->lastWeek($calendar));
    }

    public function testATimeOffCoveringTheWholeDayClosesIt(): void
    {
        [$finder, $calendar] = $this->finder(
            hours: [[1, '09:00', '12:00']],
            timeOffs: [$this->timeOff(self::NEXT_MONDAY.' 00:00', '2026-10-27 00:00', 'Congés')],
        );

        $monday = $finder->week($calendar, $this->service(60), self::paris(self::NEXT_MONDAY))[0];

        self::assertTrue($monday->isClosed());
        self::assertSame('Congés', $monday->closedReason);
    }

    public function testAPartialTimeOffOnlyBlocksTheSlotsItOverlaps(): void
    {
        [$finder, $calendar] = $this->finder(
            hours: [[1, '09:00', '12:00']],
            timeOffs: [$this->timeOff(self::NEXT_MONDAY.' 10:00', self::NEXT_MONDAY.' 11:00', 'Rendez-vous médical')],
        );

        $monday = $finder->week($calendar, $this->service(30), self::paris(self::NEXT_MONDAY))[0];

        self::assertFalse($monday->isClosed());
        self::assertSame(SlotStatus::Free, $this->statuses($monday)['09:30']);
        self::assertSame(SlotStatus::Unavailable, $this->statuses($monday)['10:00']);
        self::assertSame(SlotStatus::Unavailable, $this->statuses($monday)['10:30']);
        self::assertSame(SlotStatus::Free, $this->statuses($monday)['11:00']);
    }

    public function testIsFreeOnlyAcceptsFreeSlotsOnTheGrid(): void
    {
        [$finder, $calendar] = $this->finder(hours: [[1, '09:00', '12:00']]);
        $service = $this->service(60);

        self::assertTrue($finder->isFree($calendar, $service, self::paris(self::NEXT_MONDAY.' 10:00')));
        self::assertFalse($finder->isFree($calendar, $service, self::paris(self::NEXT_MONDAY.' 10:15')), 'Hors de la grille de 30 min');
        self::assertFalse($finder->isFree($calendar, $service, self::paris(self::NEXT_MONDAY.' 11:30')), 'Finirait après la fermeture');
        self::assertFalse($finder->isFree($calendar, $service, self::paris(self::NEXT_MONDAY.' 14:00')), 'Hors des horaires');
        self::assertFalse($finder->isFree($calendar, $service, self::paris('2026-10-19 09:00')), 'Avant le délai minimum');
    }

    public function testOpeningHoursAreInTheCompanyTimezone(): void
    {
        // Une entreprise de Montréal ouvre à 9 h, heure de Montréal (UTC-4 en octobre), soit 13 h UTC et 15 h à Paris
        [$finder, $calendar] = $this->finder(hours: [[1, '09:00', '10:00']], timezone: 'America/Montreal');

        $monday = $finder->week($calendar, $this->service(60), new \DateTimeImmutable(self::NEXT_MONDAY, new \DateTimeZone('America/Montreal')))[0];
        $slot = $monday->slots[0];

        self::assertSame('09:00', $slot->start->format('H:i'));
        self::assertSame('2026-10-26 13:00', $slot->start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        self::assertTrue($finder->isFree($calendar, $this->service(60), self::paris(self::NEXT_MONDAY.' 14:00')), '14:00 Paris = 09:00 Montréal');
    }

    public function testTheWeekFollowsDaylightSavingTime(): void
    {
        // Passage à l'heure d'hiver dans la nuit du 24 au 25 octobre 2026 : 9 h reste 9 h, mais en UTC+1
        [$finder, $calendar] = $this->finder(hours: [[5, '09:00', '10:00'], [1, '09:00', '10:00']]);
        $service = $this->service(60);

        $friday = $finder->week($calendar, $service, self::paris('2026-10-23'))[4]->slots[0];
        $monday = $finder->week($calendar, $service, self::paris(self::NEXT_MONDAY))[0]->slots[0];

        self::assertSame('07:00', $friday->start->setTimezone(new \DateTimeZone('UTC'))->format('H:i'));
        self::assertSame('08:00', $monday->start->setTimezone(new \DateTimeZone('UTC'))->format('H:i'));
    }

    public function testTheFirstWeekSkipsAWeekThatIsAlreadyOver(): void
    {
        $hours = [[1, '09:00', '12:00'], [6, '09:00', '12:00']];

        // Dimanche soir : plus rien cette semaine, l'agenda ouvre sur la suivante
        [$finder, $calendar] = $this->finder($hours, now: '2026-10-25 20:00');
        self::assertEquals(self::paris('2026-10-26'), $finder->firstWeek($calendar));
        // Samedi 08:00 : il reste la matinée du samedi (après 10:00), on reste sur la semaine en cours
        [$finder, $calendar] = $this->finder($hours, now: '2026-10-24 08:00');
        self::assertEquals(self::paris('2026-10-19'), $finder->firstWeek($calendar));
        // Samedi 11:00 : le délai de 2 h dépasse la fermeture de midi, on passe à la semaine suivante
        [$finder, $calendar] = $this->finder($hours, now: '2026-10-24 11:00');
        self::assertEquals(self::paris('2026-10-26'), $finder->firstWeek($calendar));
    }

    public function testMondayOfReturnsMidnightOnTheMondayOfTheWeek(): void
    {
        self::assertEquals(self::paris('2026-10-19 00:00'), SlotFinder::mondayOf(self::paris('2026-10-25 23:59')));
        self::assertEquals(self::paris('2026-10-19 00:00'), SlotFinder::mondayOf(self::paris('2026-10-19 08:00')));
    }

    /**
     * @param list<array{int, string, string}> $hours    [jour ISO, début, fin] en heure locale
     * @param list<TimeOff>                    $timeOffs
     * @param list<Booking>                    $bookings
     *
     * @return array{SlotFinder, Calendar}
     */
    private function finder(
        array $hours,
        array $timeOffs = [],
        array $bookings = [],
        string $now = self::NOW,
        string $timezone = 'Europe/Paris',
        ?callable $configure = null,
    ): array {
        $calendar = (new Calendar())->setName('Julie')->setSlug('julie');
        (new Company())->setName('Salon')->setSlug('salon')->setTimezone($timezone)->addCalendar($calendar);
        if (null !== $configure) {
            $configure($calendar);
        }

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
        $bookingRepository->method('findBlockingOverlapping')->willReturn($bookings);

        $finder = new SlotFinder($openingHours, $timeOffRepository, $bookingRepository, new MockClock(self::paris($now)));

        return [$finder, $calendar];
    }

    private static function paris(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Paris'));
    }

    private function service(int $minutes): Service
    {
        return (new Service())->setName("Séance $minutes min")->setDurationMinutes($minutes);
    }

    private function booking(Service $service, string $start): Booking
    {
        return (new Booking())->setService($service)->setStartsAt(self::paris($start));
    }

    private function timeOff(string $start, string $end, string $reason): TimeOff
    {
        return (new TimeOff())
            ->setStartsAt(self::paris($start))
            ->setEndsAt(self::paris($end))
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
