<?php

namespace App\Controller\Space;

use App\Entity\Calendar;
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

        $user = $this->user();
        $company = $this->company();
        $calendars = array_values(array_filter(
            $company->getCalendars()->toArray(),
            static fn (Calendar $calendar): bool => $user->manages($calendar),
        ));

        return $this->render('space/dashboard.html.twig', [
            'user' => $user,
            'company' => $company,
            'calendars' => $calendars,
            'upcoming' => $bookings->findUpcomingFor($calendars, $clock->now(), 8),
        ]);
    }
}
