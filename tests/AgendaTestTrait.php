<?php

namespace App\Tests;

use App\Entity\Booking;
use App\Entity\OpeningHour;
use App\Entity\Service;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Un agenda minimal pour les tests qui utilisent la base : chaque test crée ses données, et DAMA
 * (dama/doctrine-test-bundle) annule la transaction à la fin du test.
 *
 * L'heure est figée au lundi 19 octobre 2026, 08:00 (heure de Paris), par ClockSensitiveTrait.
 */
trait AgendaTestTrait
{
    use ClockSensitiveTrait;

    /** Lundi de la semaine suivante : entièrement réservable avec l'heure figée */
    protected const string NEXT_MONDAY = '2026-10-26';

    protected function freezeTime(string $at = '2026-10-19 08:00'): void
    {
        // MockClock interprète une chaîne en UTC : on lui donne une date déjà en heure de Paris
        static::mockTime(new \DateTimeImmutable($at, new \DateTimeZone('Europe/Paris')));
    }

    abstract protected function entityManager(): EntityManagerInterface;

    protected function createService(string $name = 'Séance individuelle', int $minutes = 60, int $priceCents = 6000, bool $active = true): Service
    {
        $service = (new Service())
            ->setName($name)
            ->setDurationMinutes($minutes)
            ->setPriceCents($priceCents)
            ->setIsActive($active);
        $this->entityManager()->persist($service);
        $this->entityManager()->flush();

        return $service;
    }

    /** Lundi → vendredi, 9h-12h et 14h-18h */
    protected function createWeekdayHours(): void
    {
        foreach (range(1, 5) as $day) {
            foreach ([['09:00', '12:00'], ['14:00', '18:00']] as [$start, $end]) {
                $this->entityManager()->persist((new OpeningHour())
                    ->setDayOfWeek($day)
                    ->setStartTime(new \DateTimeImmutable($start))
                    ->setEndTime(new \DateTimeImmutable($end)));
            }
        }
        $this->entityManager()->flush();
    }

    protected function createBooking(Service $service, string $start, string $customer = 'Camille Martin'): Booking
    {
        $booking = (new Booking())
            ->setService($service)
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setCustomerName($customer)
            ->setCustomerEmail('camille@example.com');
        $this->entityManager()->persist($booking);
        $this->entityManager()->flush();

        return $booking;
    }

    protected function createAdmin(string $role = 'ROLE_ADMIN'): User
    {
        $user = (new User())
            ->setEmail('pro@kairos.test')
            ->setRoles([$role])
            ->setPassword('inutile-en-test'); // loginUser() ne vérifie pas le mot de passe
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }
}
