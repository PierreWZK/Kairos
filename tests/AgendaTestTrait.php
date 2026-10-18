<?php

namespace App\Tests;

use App\Entity\Booking;
use App\Entity\Calendar;
use App\Entity\Company;
use App\Entity\OpeningHour;
use App\Entity\Service;
use App\Entity\User;
use App\Enum\CompanyRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Des données minimales pour les tests qui utilisent la base : chaque test crée les siennes, et DAMA
 * (dama/doctrine-test-bundle) annule la transaction à la fin du test.
 *
 * L'heure est figée au lundi 19 octobre 2026, 08:00 heure de Paris, par ClockSensitiveTrait.
 * Les dates s'écrivent en heure locale de l'entreprise : paris('2026-10-26 10:00').
 */
trait AgendaTestTrait
{
    use ClockSensitiveTrait;

    /** Lundi de la semaine suivante : entièrement réservable avec l'heure figée */
    protected const string NEXT_MONDAY = '2026-10-26';

    protected static function paris(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Paris'));
    }

    protected function freezeTime(string $at = '2026-10-19 08:00'): void
    {
        // MockClock interprète une chaîne en UTC : on lui donne une date déjà en heure de Paris
        static::mockTime(self::paris($at));
    }

    abstract protected function entityManager(): EntityManagerInterface;

    protected function createCompany(string $name = 'Salon Lumière', string $slug = 'salon-lumiere', string $timezone = 'Europe/Paris'): Company
    {
        $company = (new Company())->setName($name)->setSlug($slug)->setTimezone($timezone);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();

        return $company;
    }

    protected function createService(Company $company, string $name = 'Séance individuelle', int $minutes = 60, int $priceCents = 6000, bool $active = true): Service
    {
        $service = (new Service())
            ->setName($name)
            ->setDurationMinutes($minutes)
            ->setPriceCents($priceCents)
            ->setIsActive($active);
        $company->addService($service);
        $this->entityManager()->persist($service);
        $this->entityManager()->flush();

        return $service;
    }

    /**
     * Un calendrier ouvert du lundi au vendredi, 9h-12h et 14h-18h (heure locale), qui propose $services.
     *
     * @param list<Service> $services
     */
    protected function createCalendar(Company $company, array $services, string $slug = 'julie', ?callable $configure = null): Calendar
    {
        $calendar = (new Calendar())->setName(ucfirst($slug))->setSlug($slug);
        foreach ($services as $service) {
            $calendar->addService($service);
        }
        foreach (range(1, 5) as $day) {
            foreach ([['09:00', '12:00'], ['14:00', '18:00']] as [$start, $end]) {
                $calendar->addOpeningHour((new OpeningHour())
                    ->setDayOfWeek($day)
                    ->setStartTime(new \DateTimeImmutable($start))
                    ->setEndTime(new \DateTimeImmutable($end)));
            }
        }
        if (null !== $configure) {
            $configure($calendar);
        }
        $company->addCalendar($calendar);
        $this->entityManager()->persist($calendar);
        $this->entityManager()->flush();

        return $calendar;
    }

    /** Une entreprise de démo complète : un calendrier « julie » qui propose une séance d'1 h */
    protected function createAgenda(?callable $configure = null): Calendar
    {
        $company = $this->createCompany();

        return $this->createCalendar($company, [$this->createService($company)], configure: $configure);
    }

    /** @param string $start heure locale (Paris) */
    protected function createBooking(Calendar $calendar, Service $service, string $start, string $customer = 'Camille Martin'): Booking
    {
        $booking = (new Booking())
            ->setCalendar($calendar)
            ->setService($service)
            ->setStartsAt(self::paris($start))
            ->setCustomerName($customer)
            ->setCustomerEmail('camille@example.com');
        $this->entityManager()->persist($booking);
        $this->entityManager()->flush();

        return $booking;
    }

    protected function createSuperAdmin(): User
    {
        return $this->persistUser((new User())->setEmail('admin@kairos.test')->setName('Super-admin')->setRoles(['ROLE_SUPER_ADMIN']));
    }

    protected function createMember(Company $company, CompanyRole $role = CompanyRole::Owner, string $email = 'pro@kairos.test'): User
    {
        return $this->persistUser((new User())->setEmail($email)->setName('Julie Moreau')->setCompany($company)->setCompanyRole($role));
    }

    private function persistUser(User $user): User
    {
        // loginUser() ne vérifie pas le mot de passe
        $user->setPassword('inutile-en-test')->setIsVerified(true);
        $user->getCompany()?->getUsers()->add($user);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }
}
