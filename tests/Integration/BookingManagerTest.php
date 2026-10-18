<?php

namespace App\Tests\Integration;

use App\Entity\Booking;
use App\Entity\Calendar;
use App\Entity\Service;
use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use App\Scheduling\BookingManager;
use App\Scheduling\SlotUnavailableException;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le service de réservation avec la vraie base de test (kairos_test) : verrou, revérification, annulation.
 */
final class BookingManagerTest extends KernelTestCase
{
    use AgendaTestTrait;

    private BookingManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->freezeTime();
        $this->manager = self::getContainer()->get(BookingManager::class);
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAFreeSlotIsBookedAndStoredInUtc(): void
    {
        $calendar = $this->createAgenda();
        $booking = $this->newBooking($calendar, $this->service($calendar), self::NEXT_MONDAY.' 10:00');

        $this->manager->book($booking);

        self::assertNotNull($booking->getId());
        self::assertSame(BookingStatus::Confirmed, $booking->getStatus());
        self::assertEquals(self::paris(self::NEXT_MONDAY.' 11:00'), $booking->getEndsAt());

        // En base, 10:00 à Paris (UTC+1 après le changement d'heure) est enregistré 09:00
        $stored = $this->entityManager()->getConnection()->fetchOne('SELECT starts_at FROM booking WHERE id = ?', [$booking->getId()]);
        self::assertSame(self::NEXT_MONDAY.' 09:00:00', $stored);
    }

    public function testAnOverlappingBookingIsRefusedEvenWithADifferentStartTime(): void
    {
        $calendar = $this->createAgenda();
        $long = $this->createService($calendar->getCompany(), 'Séance approfondie', 90);
        $calendar->addService($long);
        // 09:00-10:30 est pris : 10:00-11:00 le chevauche sans commencer à la même heure
        $this->createBooking($calendar, $long, self::NEXT_MONDAY.' 09:00');

        $this->expectException(SlotUnavailableException::class);
        $this->manager->book($this->newBooking($calendar, $this->service($calendar), self::NEXT_MONDAY.' 10:00'));
    }

    public function testTwoCalendarsOfTheSameCompanyDoNotBlockEachOther(): void
    {
        $julie = $this->createAgenda();
        $service = $this->service($julie);
        $marc = $this->createCalendar($julie->getCompany(), [$service], 'marc');
        $this->createBooking($julie, $service, self::NEXT_MONDAY.' 10:00');

        $booking = $this->newBooking($marc, $service, self::NEXT_MONDAY.' 10:00');
        $this->manager->book($booking);

        self::assertNotNull($booking->getId());
    }

    public function testAServiceTheCalendarDoesNotOfferIsRefused(): void
    {
        $calendar = $this->createAgenda();
        $other = $this->createService($calendar->getCompany(), 'Couleur', 90);

        $this->expectException(SlotUnavailableException::class);
        $this->manager->book($this->newBooking($calendar, $other, self::NEXT_MONDAY.' 10:00'));
    }

    public function testWithManualApprovalTheBookingIsPendingAndBlocksTheSlot(): void
    {
        $calendar = $this->createAgenda(static fn (Calendar $c) => $c->setRequiresApproval(true));
        $service = $this->service($calendar);

        $booking = $this->newBooking($calendar, $service, self::NEXT_MONDAY.' 10:00');
        $this->manager->book($booking);
        self::assertSame(BookingStatus::Pending, $booking->getStatus());

        // Une demande en attente bloque déjà son créneau
        $this->expectException(SlotUnavailableException::class);
        $this->manager->book($this->newBooking($calendar, $service, self::NEXT_MONDAY.' 10:00'));
    }

    public function testACancelledBookingFreesItsSlot(): void
    {
        $calendar = $this->createAgenda();
        $service = $this->service($calendar);
        $this->createBooking($calendar, $service, self::NEXT_MONDAY.' 10:00')->cancel();
        $this->entityManager()->flush();

        $booking = $this->newBooking($calendar, $service, self::NEXT_MONDAY.' 10:00');
        $this->manager->book($booking);

        self::assertNotNull($booking->getId());
    }

    public function testTheRepositoryOnlyReturnsBlockingBookingsOfTheCalendar(): void
    {
        $calendar = $this->createAgenda();
        $service = $this->service($calendar);
        $kept = $this->createBooking($calendar, $service, self::NEXT_MONDAY.' 10:00');
        $this->createBooking($calendar, $service, self::NEXT_MONDAY.' 14:00')->cancel();
        $this->createBooking($this->createCalendar($calendar->getCompany(), [$service], 'marc'), $service, self::NEXT_MONDAY.' 15:00');
        $this->entityManager()->flush();

        $found = self::getContainer()->get(BookingRepository::class)->findBlockingOverlapping(
            $calendar,
            self::paris(self::NEXT_MONDAY),
            self::paris('2026-10-27'),
        );

        self::assertSame([$kept->getId()], array_map(static fn (Booking $b): ?int => $b->getId(), $found));
    }

    public function testTheCancellationDeadlineComesFromTheCalendar(): void
    {
        $calendar = $this->createAgenda(static fn (Calendar $c) => $c->setCancelNoticeHours(24));
        $booking = $this->createBooking($calendar, $this->service($calendar), '2026-10-20 14:00');

        $this->freezeTime('2026-10-19 13:59');
        self::assertTrue($this->manager->canCancel($booking));

        $this->freezeTime('2026-10-19 14:01');
        self::assertFalse($this->manager->canCancel($booking));
        self::assertFalse($this->manager->cancel($booking));
        self::assertFalse($booking->isCancelled());
    }

    public function testCancellingTwiceDoesNothingTheSecondTime(): void
    {
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $this->service($calendar), self::NEXT_MONDAY.' 10:00');

        self::assertTrue($this->manager->cancel($booking));
        self::assertTrue($booking->isCancelled());
        self::assertFalse($this->manager->cancel($booking));
    }

    private function service(Calendar $calendar): Service
    {
        return $calendar->getServices()->first();
    }

    private function newBooking(Calendar $calendar, Service $service, string $start): Booking
    {
        return (new Booking())
            ->setCalendar($calendar)
            ->setService($service)
            ->setStartsAt(self::paris($start))
            ->setCustomerName('Hugo Bernard')
            ->setCustomerEmail('hugo@example.com');
    }
}
