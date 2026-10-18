<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Service;
use App\Form\BookingType;
use App\Notification\BookingMailer;
use App\Repository\ServiceRepository;
use App\Scheduling\BookingManager;
use App\Scheduling\SlotFinder;
use App\Scheduling\SlotUnavailableException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class BookingController extends AbstractController
{
    #[Route('/reserver', name: 'app_booking_services', methods: ['GET'])]
    public function services(ServiceRepository $services): Response
    {
        return $this->render('booking/services.html.twig', [
            'services' => $services->findActive(),
        ]);
    }

    #[Route('/reserver/{id<\d+>}', name: 'app_booking_agenda', methods: ['GET'])]
    public function agenda(Service $service, SlotFinder $slotFinder, #[MapQueryParameter] ?string $semaine = null): Response
    {
        $this->denyUnlessOffered($service);

        $firstWeek = $slotFinder->firstWeek();
        $lastWeek = $slotFinder->lastWeek();

        // ?semaine=2026-10-19 : n'importe quel jour de la semaine voulue, ramené dans la période réservable
        $week = $firstWeek;
        $requested = null !== $semaine ? \DateTimeImmutable::createFromFormat('!Y-m-d', $semaine) : false;
        if (false !== $requested) {
            $week = max($firstWeek, min($lastWeek, SlotFinder::mondayOf($requested)));
        }

        $previous = $week->modify('-7 days');
        $next = $week->modify('+7 days');

        return $this->render('booking/agenda.html.twig', [
            'service' => $service,
            'week' => $week,
            'days' => $slotFinder->week($service, $week),
            'previous_week' => $previous >= $firstWeek ? $previous : null,
            'next_week' => $next <= $lastWeek ? $next : null,
        ]);
    }

    #[Route('/reserver/{id<\d+>}/creneau', name: 'app_booking_new', methods: ['GET', 'POST'])]
    public function new(
        Service $service,
        Request $request,
        SlotFinder $slotFinder,
        BookingManager $bookingManager,
        BookingMailer $mailer,
        #[MapQueryParameter] string $debut = '',
    ): Response {
        $this->denyUnlessOffered($service);

        // ?debut=2026-10-19T10:00
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $debut);
        if (false === $start) {
            return $this->redirectToRoute('app_booking_agenda', ['id' => $service->getId()]);
        }

        if (!$slotFinder->isFree($service, $start)) {
            return $this->backToAgenda($service, $start);
        }

        $booking = (new Booking())
            ->setService($service)
            ->setStartsAt($start);

        $form = $this->createForm(BookingType::class, $booking);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Revérifie le créneau sous verrou : il a pu être pris pendant que le client remplissait le formulaire
                $bookingManager->book($booking);
            } catch (SlotUnavailableException) {
                return $this->backToAgenda($service, $start);
            }

            $mailer->sendConfirmation($booking);

            return $this->redirectToRoute('app_booking_show', ['token' => $booking->getCancelToken()]);
        }

        return $this->render('booking/new.html.twig', [
            'service' => $service,
            'booking' => $booking,
            'form' => $form,
        ]);
    }

    /** La page du rendez-vous, connue du seul client : son adresse contient le jeton secret */
    #[Route('/rendez-vous/{token<[a-f0-9]{64}>}', name: 'app_booking_show', methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['token' => 'cancelToken'])] Booking $booking,
        BookingManager $bookingManager,
        ClockInterface $clock,
    ): Response {
        return $this->render('booking/show.html.twig', [
            'booking' => $booking,
            'can_cancel' => $bookingManager->canCancel($booking),
            'cancel_notice_hours' => $bookingManager->getCancelNoticeHours(),
            'is_past' => $booking->getEndsAt() <= $clock->now(),
        ]);
    }

    #[Route('/rendez-vous/{token<[a-f0-9]{64}>}/annuler', name: 'app_booking_cancel', methods: ['POST'])]
    public function cancel(
        #[MapEntity(mapping: ['token' => 'cancelToken'])] Booking $booking,
        Request $request,
        BookingManager $bookingManager,
        BookingMailer $mailer,
    ): Response {
        if (!$this->isCsrfTokenValid('cancel-booking', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($bookingManager->cancel($booking)) {
            $mailer->sendCancellation($booking);
            $this->addFlash('success', 'Votre rendez-vous est annulé. Un e-mail de confirmation vous a été envoyé.');
        } elseif (!$booking->isCancelled()) {
            $this->addFlash('warning', sprintf('Ce rendez-vous commence dans moins de %d h : il ne peut plus être annulé en ligne.', $bookingManager->getCancelNoticeHours()));
        }

        return $this->redirectToRoute('app_booking_show', ['token' => $booking->getCancelToken()]);
    }

    private function denyUnlessOffered(Service $service): void
    {
        if (!$service->isActive()) {
            throw $this->createNotFoundException('Cette prestation n\'est pas proposée.');
        }
    }

    private function backToAgenda(Service $service, \DateTimeImmutable $start): Response
    {
        $this->addFlash('warning', 'Ce créneau n\'est plus disponible. Choisissez-en un autre.');

        return $this->redirectToRoute('app_booking_agenda', [
            'id' => $service->getId(),
            'semaine' => $start->format('Y-m-d'),
        ]);
    }
}
