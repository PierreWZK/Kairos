<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Calendar;
use App\Entity\Company;
use App\Entity\Service;
use App\Form\BookingType;
use App\Notification\BookingMailer;
use App\Repository\CalendarRepository;
use App\Repository\CompanyRepository;
use App\Repository\ServiceRepository;
use App\Scheduling\BookingManager;
use App\Scheduling\SlotFinder;
use App\Scheduling\SlotUnavailableException;
use App\Twig\DisplayTimezone;
use App\Twig\PublicPathExtension;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le parcours public : /{entreprise} → /{entreprise}/{calendrier} → agenda d'une prestation → réservation.
 *
 * Ces adresses attrapent tout ce qui ressemble à un lien d'entreprise : leur priorité négative les fait passer
 * après les routes de l'appli (/admin, /connexion…), dont les noms sont par ailleurs réservés (Company::RESERVED_SLUGS).
 *
 * Chaque page a une jumelle sous /integration, affichée dans l'iframe du site de l'entreprise (voir PublicPathExtension).
 * Le parcours n'utilise pas la session : dans l'iframe d'un autre site, son cookie n'est pas envoyé.
 */
final class BookingController extends AbstractController
{
    private const array SLUG = ['company' => Company::SLUG_PATTERN, 'calendar' => Company::SLUG_PATTERN];
    private const array EMBED = ['_embed' => true];

    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly CalendarRepository $calendars,
        private readonly ServiceRepository $services,
        private readonly DisplayTimezone $displayTimezone,
        private readonly PublicPathExtension $publicPaths,
    ) {
    }

    #[Route('/{company}', name: 'app_company', requirements: self::SLUG, methods: ['GET'], priority: -10)]
    #[Route('/integration/{company}', name: 'app_company_embed', requirements: self::SLUG, defaults: self::EMBED, methods: ['GET'], priority: -5)]
    public function company(string $company): Response
    {
        $company = $this->findCompany($company);

        return $this->render('booking/company.html.twig', [
            'company' => $company,
            'calendars' => $company->getActiveCalendars(),
        ]);
    }

    #[Route('/{company}/{calendar}', name: 'app_calendar', requirements: self::SLUG, methods: ['GET'], priority: -10)]
    #[Route('/integration/{company}/{calendar}', name: 'app_calendar_embed', requirements: self::SLUG, defaults: self::EMBED, methods: ['GET'], priority: -5)]
    public function calendar(string $company, string $calendar): Response
    {
        $calendar = $this->findCalendar($company, $calendar);

        return $this->render('booking/services.html.twig', [
            'company' => $calendar->getCompany(),
            'calendar' => $calendar,
            'services' => $calendar->getOfferedServices(),
        ]);
    }

    #[Route('/{company}/{calendar}/{service}', name: 'app_booking_agenda', requirements: self::SLUG + ['service' => '\d+'], methods: ['GET'], priority: -10)]
    #[Route('/integration/{company}/{calendar}/{service}', name: 'app_booking_agenda_embed', requirements: self::SLUG + ['service' => '\d+'], defaults: self::EMBED, methods: ['GET'], priority: -5)]
    public function agenda(
        string $company,
        string $calendar,
        int $service,
        SlotFinder $slotFinder,
        #[MapQueryParameter] ?string $semaine = null,
        #[MapQueryParameter] bool $indisponible = false,
    ): Response {
        $calendar = $this->findCalendar($company, $calendar);
        $service = $this->findService($calendar, $service);

        $firstWeek = $slotFinder->firstWeek($calendar);
        $lastWeek = $slotFinder->lastWeek($calendar);

        // ?semaine=2026-10-19 : n'importe quel jour de la semaine voulue, ramené dans la période réservable
        $week = $firstWeek;
        $requested = null !== $semaine ? \DateTimeImmutable::createFromFormat('!Y-m-d', $semaine, $calendar->getDateTimeZone()) : false;
        if (false !== $requested) {
            $week = max($firstWeek, min($lastWeek, SlotFinder::mondayOf($requested)));
        }

        $previous = $week->modify('-7 days');
        $next = $week->modify('+7 days');

        return $this->render('booking/agenda.html.twig', [
            'company' => $calendar->getCompany(),
            'calendar' => $calendar,
            'service' => $service,
            'week' => $week,
            'days' => $slotFinder->week($calendar, $service, $week),
            'previous_week' => $previous >= $firstWeek ? $previous : null,
            'next_week' => $next <= $lastWeek ? $next : null,
            // Renvoyé ici parce que le créneau choisi venait d'être pris
            'slot_taken' => $indisponible,
        ]);
    }

    #[Route('/{company}/{calendar}/{service}/creneau', name: 'app_booking_new', requirements: self::SLUG + ['service' => '\d+'], methods: ['GET', 'POST'], priority: -10)]
    #[Route('/integration/{company}/{calendar}/{service}/creneau', name: 'app_booking_new_embed', requirements: self::SLUG + ['service' => '\d+'], defaults: self::EMBED, methods: ['GET', 'POST'], priority: -5)]
    public function new(
        string $company,
        string $calendar,
        int $service,
        Request $request,
        SlotFinder $slotFinder,
        BookingManager $bookingManager,
        BookingMailer $mailer,
        #[MapQueryParameter] string $debut = '',
    ): Response {
        $calendar = $this->findCalendar($company, $calendar);
        $service = $this->findService($calendar, $service);

        // ?debut=2026-10-19T10:00, en heure locale de l'entreprise
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $debut, $calendar->getDateTimeZone());
        if (false === $start) {
            return $this->redirectToPublic('app_booking_agenda', $this->routeParams($calendar, $service));
        }

        if (!$slotFinder->isFree($calendar, $service, $start)) {
            return $this->backToAgenda($calendar, $service, $start);
        }

        $booking = (new Booking())
            ->setCalendar($calendar)
            ->setService($service)
            ->setStartsAt($start);

        $form = $this->createForm(BookingType::class, $booking, ['calendar' => $calendar]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Revérifie le créneau sous verrou : il a pu être pris pendant que le client remplissait le formulaire
                $bookingManager->book($booking);
            } catch (SlotUnavailableException) {
                return $this->backToAgenda($calendar, $service, $start);
            }

            $mailer->sendConfirmation($booking);

            return $this->redirectToPublic('app_booking_show', ['token' => $booking->getCancelToken()]);
        }

        return $this->render('booking/new.html.twig', [
            'company' => $calendar->getCompany(),
            'calendar' => $calendar,
            'service' => $service,
            'booking' => $booking,
            'form' => $form,
        ]);
    }

    /** La page du rendez-vous, connue du seul client : son adresse contient le jeton secret */
    #[Route('/rendez-vous/{token<[a-f0-9]{64}>}', name: 'app_booking_show', methods: ['GET'])]
    #[Route('/integration/rendez-vous/{token<[a-f0-9]{64}>}', name: 'app_booking_show_embed', defaults: self::EMBED, methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['token' => 'cancelToken'])] Booking $booking,
        BookingManager $bookingManager,
        ClockInterface $clock,
    ): Response {
        $this->displayTimezone->useCompany($booking->getCompany());

        return $this->render('booking/show.html.twig', [
            'booking' => $booking,
            'company' => $booking->getCompany(),
            'calendar' => $booking->getCalendar(),
            'can_cancel' => $bookingManager->canCancel($booking),
            'is_past' => $booking->getEndsAt() <= $clock->now(),
        ]);
    }

    #[Route('/rendez-vous/{token<[a-f0-9]{64}>}/annuler', name: 'app_booking_cancel', methods: ['POST'])]
    #[Route('/integration/rendez-vous/{token<[a-f0-9]{64}>}/annuler', name: 'app_booking_cancel_embed', defaults: self::EMBED, methods: ['POST'])]
    public function cancel(
        #[MapEntity(mapping: ['token' => 'cancelToken'])] Booking $booking,
        Request $request,
        BookingManager $bookingManager,
        BookingMailer $mailer,
    ): Response {
        if (!$this->isCsrfTokenValid('cancel-booking', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        // Trop tard pour annuler : la page du rendez-vous l'explique d'elle-même
        if ($bookingManager->cancel($booking)) {
            $mailer->sendCancellation($booking);
        }

        return $this->redirectToPublic('app_booking_show', ['token' => $booking->getCancelToken()]);
    }

    /** L'entreprise publique à cette adresse ; ses dates s'affichent dans son fuseau */
    private function findCompany(string $slug): Company
    {
        $company = $this->companies->findPublicBySlug($slug) ?? throw $this->createNotFoundException('Entreprise introuvable.');
        $this->displayTimezone->useCompany($company);

        return $company;
    }

    private function findCalendar(string $companySlug, string $calendarSlug): Calendar
    {
        $company = $this->findCompany($companySlug);

        return $this->calendars->findPublic($company, $calendarSlug) ?? throw $this->createNotFoundException('Calendrier introuvable.');
    }

    /** Une prestation active, proposée sur ce calendrier : un id d'une autre entreprise donne une 404 */
    private function findService(Calendar $calendar, int $id): Service
    {
        $service = $this->services->find($id);
        if (null === $service || !$calendar->offers($service)) {
            throw $this->createNotFoundException('Cette prestation n\'est pas proposée ici.');
        }

        return $service;
    }

    /** @return array{company: string, calendar: string, service: ?int} */
    private function routeParams(Calendar $calendar, Service $service): array
    {
        return ['company' => $calendar->getCompany()->getSlug(), 'calendar' => $calendar->getSlug(), 'service' => $service->getId()];
    }

    private function backToAgenda(Calendar $calendar, Service $service, \DateTimeImmutable $start): Response
    {
        return $this->redirectToPublic('app_booking_agenda', [
            ...$this->routeParams($calendar, $service),
            'semaine' => $start->format('Y-m-d'),
            'indisponible' => 1,
        ]);
    }

    /**
     * Redirige en restant dans la version de la page (Kairos ou intégrée)
     *
     * @param array<string, mixed> $parameters
     */
    private function redirectToPublic(string $route, array $parameters): Response
    {
        return $this->redirectToRoute($this->publicPaths->route($route), $parameters);
    }
}
