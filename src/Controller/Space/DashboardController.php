<?php

namespace App\Controller\Space;

use App\Repository\BookingRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'accueil de l'espace entreprise : là où arrive un membre d'entreprise après sa connexion.
 * C'est la seule page ouverte tant que l'adresse e-mail n'est pas confirmée.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class DashboardController extends AbstractSpaceController
{
    #[Route('/espace', name: 'app_space', methods: ['GET'])]
    public function index(BookingRepository $bookings, ClockInterface $clock): Response
    {
        // Le super-admin n'a pas d'entreprise : son espace, c'est EasyAdmin
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin');
        }

        $calendars = $this->managedCalendars();

        return $this->render('space/dashboard.html.twig', [
            'user' => $this->user(),
            'company' => $this->company(),
            'calendars' => $calendars,
            'upcoming' => $bookings->findUpcomingFor($calendars, $clock->now(), 8),
            'pending_count' => $bookings->countPendingFor($calendars, $clock->now()),
        ]);
    }
}
