<?php

namespace App\Controller\Space;

use App\Entity\Booking;
use App\Enum\BookingStatus;
use App\Notification\BookingMailer;
use App\Repository\BookingRepository;
use App\Security\Voter\SpaceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les rendez-vous des calendriers que l'utilisateur gère : liste filtrée, fiche, et les décisions
 * (accepter ou refuser une demande, annuler un rendez-vous), chacune annoncée au client par e-mail.
 */
#[IsGranted('ROLE_COMPANY_MEMBER')]
#[Route('/espace/rendez-vous')]
final class BookingController extends AbstractSpaceController
{
    private const int LIST_LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_space_bookings', methods: ['GET'])]
    public function index(
        BookingRepository $bookings,
        #[MapQueryParameter] string $periode = 'a-venir',
        #[MapQueryParameter] string $statut = '',
        #[MapQueryParameter] ?int $calendrier = null,
    ): Response {
        $calendars = $this->managedCalendars();
        $selected = array_values(array_filter($calendars, static fn ($c): bool => $c->getId() === $calendrier));
        $upcoming = 'passes' !== $periode;
        $status = BookingStatus::tryFrom($statut);

        return $this->render('space/bookings/index.html.twig', [
            'calendars' => $calendars,
            'bookings' => $bookings->findForSpace($selected ?: $calendars, $this->clock->now(), $upcoming, $status, self::LIST_LIMIT),
            'limit' => self::LIST_LIMIT,
            'statuses' => BookingStatus::cases(),
            'filters' => [
                'periode' => $upcoming ? 'a-venir' : 'passes',
                'statut' => $status?->value ?? '',
                'calendrier' => $selected ? $calendrier : null,
            ],
        ]);
    }

    #[Route('/{id<\d+>}', name: 'app_space_booking_show', methods: ['GET'])]
    public function show(Booking $booking): Response
    {
        $this->denyAccessUnlessGranted(SpaceVoter::MANAGE_CALENDAR, $booking->getCalendar());

        return $this->render('space/bookings/show.html.twig', [
            'booking' => $booking,
            'is_past' => $booking->getEndsAt() <= $this->clock->now(),
        ]);
    }

    #[Route('/{id<\d+>}/accepter', name: 'app_space_booking_accept', methods: ['POST'])]
    public function accept(Booking $booking, Request $request): Response
    {
        $this->check($booking, $request, 'accept');

        if ($booking->accept()) {
            $this->entityManager->flush();
            $this->mailer->sendAccepted($booking);
            $this->addFlash('success', sprintf('Rendez-vous confirmé : %s a reçu la confirmation par e-mail.', $booking->getCustomerName()));
        }

        return $this->redirectToRoute('app_space_booking_show', ['id' => $booking->getId()]);
    }

    #[Route('/{id<\d+>}/refuser', name: 'app_space_booking_reject', methods: ['POST'])]
    public function reject(Booking $booking, Request $request): Response
    {
        $this->check($booking, $request, 'reject');

        if ($booking->reject()) {
            $this->entityManager->flush();
            $this->mailer->sendRejected($booking);
            $this->addFlash('success', sprintf('Demande refusée : un e-mail a été envoyé à %s, et le créneau est de nouveau libre.', $booking->getCustomerName()));
        }

        return $this->redirectToRoute('app_space_booking_show', ['id' => $booking->getId()]);
    }

    /** L'entreprise peut annuler à tout moment avant la fin du rendez-vous, sans le délai imposé au client */
    #[Route('/{id<\d+>}/annuler', name: 'app_space_booking_cancel', methods: ['POST'])]
    public function cancel(Booking $booking, Request $request): Response
    {
        $this->check($booking, $request, 'cancel');

        if ($booking->getStatus()->blocksSlot()) {
            $booking->cancel();
            $this->entityManager->flush();
            $this->mailer->sendCancellation($booking, byCompany: true);
            $this->addFlash('success', sprintf('Rendez-vous annulé : un e-mail a été envoyé à %s.', $booking->getCustomerName()));
        }

        return $this->redirectToRoute('app_space_booking_show', ['id' => $booking->getId()]);
    }

    /** Droits sur le calendrier, jeton CSRF, et pas de décision sur un rendez-vous terminé */
    private function check(Booking $booking, Request $request, string $action): void
    {
        $this->denyAccessUnlessGranted(SpaceVoter::MANAGE_CALENDAR, $booking->getCalendar());

        if (!$this->isCsrfTokenValid($action.'-booking-'.$booking->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($booking->getEndsAt() <= $this->clock->now()) {
            throw $this->createAccessDeniedException('Ce rendez-vous est terminé.');
        }
    }
}
