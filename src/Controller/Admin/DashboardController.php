<?php

namespace App\Controller\Admin;

use App\Repository\BookingRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Accueil du back-office : les rendez-vous du jour et les suivants */
    public function index(): Response
    {
        $now = $this->clock->now();
        $tomorrow = $now->setTime(0, 0)->modify('+1 day');

        $today = [];
        $next = [];
        foreach ($this->bookings->findUpcoming($now, 15) as $booking) {
            if ($booking->getStartsAt() < $tomorrow) {
                $today[] = $booking;
            } else {
                $next[] = $booking;
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'now' => $now,
            'today' => $today,
            'next' => \array_slice($next, 0, 10),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<strong>Kairos</strong> · super-admin')
            ->setTranslationDomain('admin');
    }

    /** Dates stockées en UTC, affichées à l'heure de Paris dans tout le back-office */
    public function configureCrud(): Crud
    {
        return parent::configureCrud()->setTimezone('Europe/Paris');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-gauge');

        yield MenuItem::section('Plateforme');
        yield MenuItem::linkTo(CompanyCrudController::class, 'Entreprises', 'fa fa-building');
        yield MenuItem::linkTo(CalendarCrudController::class, 'Calendriers', 'fa fa-calendar');

        yield MenuItem::section('Agenda');
        yield MenuItem::linkTo(BookingCrudController::class, 'Réservations', 'fa fa-calendar-check');
        yield MenuItem::linkTo(TimeOffCrudController::class, 'Fermetures', 'fa fa-umbrella-beach');

        yield MenuItem::section('Configuration');
        yield MenuItem::linkTo(ServiceCrudController::class, 'Prestations', 'fa fa-briefcase');
        yield MenuItem::linkTo(OpeningHourCrudController::class, 'Horaires d\'ouverture', 'fa fa-clock');

        yield MenuItem::section();
        yield MenuItem::linkToRoute('Voir le site', 'fa fa-arrow-up-right-from-square', 'app_home')
            ->setLinkTarget('_blank');
    }
}
