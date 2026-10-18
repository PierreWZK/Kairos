<?php

namespace App\DataFixtures;

use App\Entity\Booking;
use App\Entity\OpeningHour;
use App\Entity\Service;
use App\Entity\TimeOff;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Données de démo : un coach fictif, ses prestations, ses horaires et quelques rendez-vous.
 * Les dates sont calculées à partir d'aujourd'hui pour que l'agenda ne soit jamais vide.
 */
class AppFixtures extends Fixture
{
    private const array CUSTOMERS = [
        ['Camille Martin', 'camille.martin@example.com'],
        ['Hugo Bernard', 'hugo.bernard@example.com'],
        ['Léa Dubois', 'lea.dubois@example.com'],
        ['Nathan Petit', 'nathan.petit@example.com'],
        ['Inès Moreau', 'ines.moreau@example.com'],
        ['Lucas Laurent', 'lucas.laurent@example.com'],
    ];

    /** Rendez-vous d'un jour de semaine : [heure, index de la prestation], en rotation d'un jour à l'autre */
    private const array WEEKDAY_PATTERNS = [
        [['10:00', 1], ['15:00', 1]],
        [['09:00', 0], ['14:30', 2]],
        [['11:00', 1]],
        [['09:30', 0], ['16:00', 1]],
        [['14:00', 1], ['16:30', 0]],
    ];

    public function load(ObjectManager $manager): void
    {
        $services = $this->loadServices($manager);
        $this->loadOpeningHours($manager);
        $timeOffDay = $this->loadTimeOff($manager);
        $this->loadBookings($manager, $services, $timeOffDay);

        $manager->flush();
    }

    /** @return list<Service> */
    private function loadServices(ObjectManager $manager): array
    {
        $data = [
            ['Appel découverte', 30, 0, 'Un premier échange pour faire connaissance et définir vos objectifs.'],
            ['Séance individuelle', 60, 6000, 'La séance classique : un point complet et un plan d\'action concret.'],
            ['Séance approfondie', 90, 8500, 'Plus de temps pour travailler un sujet en profondeur.'],
        ];

        $services = [];
        foreach ($data as [$name, $duration, $price, $description]) {
            $service = (new Service())
                ->setName($name)
                ->setDurationMinutes($duration)
                ->setPriceCents($price)
                ->setDescription($description);
            $manager->persist($service);
            $services[] = $service;
        }

        // Une prestation désactivée : visible dans le back-office, jamais proposée aux clients
        $manager->persist((new Service())
            ->setName('Atelier collectif')
            ->setDurationMinutes(120)
            ->setPriceCents(3000)
            ->setIsActive(false));

        return $services;
    }

    private function loadOpeningHours(ObjectManager $manager): void
    {
        // Lundi → vendredi : 9h-12h et 14h-18h
        foreach (range(1, 5) as $day) {
            $manager->persist($this->openingHour($day, '09:00', '12:00'));
            $manager->persist($this->openingHour($day, '14:00', '18:00'));
        }

        // Samedi : le matin seulement
        $manager->persist($this->openingHour(6, '09:00', '12:00'));
    }

    /** Une journée de formation, sur un jour de semaine dans environ une semaine et demie */
    private function loadTimeOff(ObjectManager $manager): \DateTimeImmutable
    {
        $day = $this->nextWeekday(new \DateTimeImmutable('today +10 days'));

        $manager->persist((new TimeOff())
            ->setStartsAt($day)
            ->setEndsAt($day->modify('+1 day'))
            ->setReason('Formation'));

        return $day;
    }

    /** @param list<Service> $services */
    private function loadBookings(ObjectManager $manager, array $services, \DateTimeImmutable $timeOffDay): void
    {
        $customer = 0;
        $pattern = 0;

        for ($offset = 1; $offset <= 14; ++$offset) {
            $day = new \DateTimeImmutable("today +$offset days");
            $weekday = (int) $day->format('N');

            if (7 === $weekday || $day == $timeOffDay) {
                continue;
            }

            $slots = 6 === $weekday ? [['10:00', 1]] : self::WEEKDAY_PATTERNS[$pattern++ % \count(self::WEEKDAY_PATTERNS)];

            foreach ($slots as [$time, $serviceIndex]) {
                [$name, $email] = self::CUSTOMERS[$customer++ % \count(self::CUSTOMERS)];

                $manager->persist((new Booking())
                    ->setService($services[$serviceIndex])
                    ->setStartsAt($day->modify($time))
                    ->setCustomerName($name)
                    ->setCustomerEmail($email));
            }
        }

        // Un rendez-vous annulé : son créneau doit apparaître comme libre
        $cancelled = (new Booking())
            ->setService($services[1])
            ->setStartsAt($this->nextWeekday(new \DateTimeImmutable('today +1 day'))->modify('17:00'))
            ->setCustomerName('Manon Girard')
            ->setCustomerEmail('manon.girard@example.com')
            ->setNote('Empêchement de dernière minute.');
        $cancelled->cancel();
        $manager->persist($cancelled);
    }

    private function openingHour(int $day, string $start, string $end): OpeningHour
    {
        return (new OpeningHour())
            ->setDayOfWeek($day)
            ->setStartTime(new \DateTimeImmutable($start))
            ->setEndTime(new \DateTimeImmutable($end));
    }

    private function nextWeekday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        while ((int) $day->format('N') >= 6) {
            $day = $day->modify('+1 day');
        }

        return $day;
    }
}
