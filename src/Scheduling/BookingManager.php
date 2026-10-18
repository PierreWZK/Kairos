<?php

namespace App\Scheduling;

use App\Entity\Booking;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

final class BookingManager
{
    public function __construct(
        private readonly SlotFinder $slotFinder,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Enregistre le rendez-vous si son créneau est encore libre.
     *
     * Le verrou porte sur tout le calendrier, pas sur un créneau : deux rendez-vous qui ne commencent pas à la même
     * heure peuvent se chevaucher (une séance de 1 h 30 à 9 h et une d'1 h à 10 h). Entre la prise du verrou et sa
     * libération, personne d'autre ne peut vérifier puis enregistrer sur ce calendrier : la vérification reste vraie
     * jusqu'au flush. Deux calendriers différents ne se bloquent pas entre eux.
     *
     * @throws SlotUnavailableException si le créneau a été pris entre-temps (ou n'est plus réservable)
     */
    public function book(Booking $booking): void
    {
        $calendar = $booking->getCalendar();
        $lock = $this->lockFactory->createLock('calendar-'.$calendar->getId());
        $lock->acquire(true);

        try {
            if (!$calendar->offers($booking->getService())
                || !$this->slotFinder->isFree($calendar, $booking->getService(), $booking->getStartsAt())) {
                throw new SlotUnavailableException();
            }

            if ($calendar->requiresApproval()) {
                $booking->markPending();
            }

            $this->entityManager->persist($booking);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    /** Le client peut-il encore annuler lui-même ? Oui jusqu'à N heures (réglage du calendrier) avant le début. */
    public function canCancel(Booking $booking): bool
    {
        if (!$booking->getStatus()->blocksSlot()) {
            return false;
        }

        $deadline = $this->clock->now()->modify(sprintf('+%d hours', $booking->getCalendar()->getCancelNoticeHours()));

        return $booking->getStartsAt() > $deadline;
    }

    /** @return bool false si le rendez-vous ne peut plus être annulé en ligne */
    public function cancel(Booking $booking): bool
    {
        if (!$this->canCancel($booking)) {
            return false;
        }

        $booking->cancel();
        $this->entityManager->flush();

        return true;
    }
}
