<?php

namespace App\Tests\Integration;

use App\Entity\Booking;
use App\Entity\Service;
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
        $this->createWeekdayHours();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAFreeSlotIsBooked(): void
    {
        $booking = $this->newBooking($this->createService(), self::NEXT_MONDAY.' 10:00');

        $this->manager->book($booking);

        self::assertNotNull($booking->getId());
        self::assertEquals(new \DateTimeImmutable(self::NEXT_MONDAY.' 11:00'), $booking->getEndsAt());
    }

    public function testAnOverlappingBookingIsRefusedEvenWithADifferentStartTime(): void
    {
        // 09:00-10:30 est pris : 10:00-11:00 le chevauche sans commencer à la même heure
        $this->createBooking($this->createService('Séance approfondie', 90), self::NEXT_MONDAY.' 09:00');

        $this->expectException(SlotUnavailableException::class);
        $this->manager->book($this->newBooking($this->createService(), self::NEXT_MONDAY.' 10:00'));
    }

    public function testACancelledBookingFreesItsSlot(): void
    {
        $service = $this->createService();
        $this->createBooking($service, self::NEXT_MONDAY.' 10:00')->cancel();
        $this->entityManager()->flush();

        $booking = $this->newBooking($service, self::NEXT_MONDAY.' 10:00');
        $this->manager->book($booking);

        self::assertNotNull($booking->getId());
    }

    public function testTheRepositoryIgnoresCancelledBookings(): void
    {
        $service = $this->createService();
        $kept = $this->createBooking($service, self::NEXT_MONDAY.' 10:00');
        $this->createBooking($service, self::NEXT_MONDAY.' 14:00')->cancel();
        $this->entityManager()->flush();

        $found = self::getContainer()->get(BookingRepository::class)->findConfirmedOverlapping(
            new \DateTimeImmutable(self::NEXT_MONDAY),
            new \DateTimeImmutable('2026-10-27'),
        );

        self::assertSame([$kept->getId()], array_map(static fn (Booking $b): ?int => $b->getId(), $found));
    }

    public function testTheCustomerCanCancelUntilTwoHoursBefore(): void
    {
        $booking = $this->createBooking($this->createService(), '2026-10-19 14:00');

        $this->freezeTime('2026-10-19 11:59');
        self::assertTrue($this->manager->canCancel($booking));

        $this->freezeTime('2026-10-19 12:01');
        self::assertFalse($this->manager->canCancel($booking));
        self::assertFalse($this->manager->cancel($booking));
        self::assertFalse($booking->isCancelled());
    }

    public function testCancellingTwiceDoesNothingTheSecondTime(): void
    {
        $booking = $this->createBooking($this->createService(), self::NEXT_MONDAY.' 10:00');

        self::assertTrue($this->manager->cancel($booking));
        self::assertTrue($booking->isCancelled());
        self::assertFalse($this->manager->cancel($booking));
    }

    private function newBooking(Service $service, string $start): Booking
    {
        return (new Booking())
            ->setService($service)
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setCustomerName('Hugo Bernard')
            ->setCustomerEmail('hugo@example.com');
    }
}
