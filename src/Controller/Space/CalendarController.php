<?php

namespace App\Controller\Space;

use App\Entity\Calendar;
use App\Entity\TimeOff;
use App\Form\Space\CalendarSettingsType;
use App\Form\Space\OpeningHoursType;
use App\Form\Space\TimeOffType;
use App\Repository\BookingRepository;
use App\Repository\TimeOffRepository;
use App\Security\Voter\SpaceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les calendriers de l'entreprise : réglages, horaires, fermetures, code d'intégration.
 *
 * Le propriétaire les gère tous, crée et supprime ; un membre gère ceux qui lui sont attribués (SpaceVoter).
 */
#[IsGranted('ROLE_COMPANY_MEMBER')]
#[Route('/espace/calendriers')]
final class CalendarController extends AbstractSpaceController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_space_calendars', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('space/calendars/index.html.twig', ['calendars' => $this->managedCalendars()]);
    }

    #[Route('/nouveau', name: 'app_space_calendar_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_COMPANY_OWNER')]
    public function new(Request $request): Response
    {
        $company = $this->company();
        $calendar = (new Calendar())->setCompany($company)->addDefaultOpeningHours();
        // Par défaut, toutes les prestations réservables du catalogue
        foreach ($company->getServices() as $service) {
            if ($service->isActive()) {
                $calendar->addService($service);
            }
        }

        $form = $this->createForm(CalendarSettingsType::class, $calendar);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $company->addCalendar($calendar);
            $this->entityManager->persist($calendar);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Le calendrier « %s » est créé, ouvert du lundi au vendredi. Ajustez ses horaires si besoin.', $calendar->getName()));

            return $this->redirectToRoute('app_space_calendar_hours', ['id' => $calendar->getId()]);
        }

        return $this->render('space/calendars/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id<\d+>}', name: 'app_space_calendar_edit', methods: ['GET', 'POST'])]
    #[IsGranted(SpaceVoter::MANAGE_CALENDAR, 'calendar')]
    public function edit(Calendar $calendar, Request $request): Response
    {
        $form = $this->createForm(CalendarSettingsType::class, $calendar);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Les réglages sont enregistrés.');

            return $this->redirectToRoute('app_space_calendar_edit', ['id' => $calendar->getId()]);
        }

        return $this->render('space/calendars/settings.html.twig', ['calendar' => $calendar, 'form' => $form]);
    }

    #[Route('/{id<\d+>}/horaires', name: 'app_space_calendar_hours', methods: ['GET', 'POST'])]
    #[IsGranted(SpaceVoter::MANAGE_CALENDAR, 'calendar')]
    public function hours(Calendar $calendar, Request $request): Response
    {
        $form = $this->createForm(OpeningHoursType::class, $calendar);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Les horaires sont enregistrés.');

            return $this->redirectToRoute('app_space_calendar_hours', ['id' => $calendar->getId()]);
        }

        return $this->render('space/calendars/hours.html.twig', ['calendar' => $calendar, 'form' => $form]);
    }

    #[Route('/{id<\d+>}/fermetures', name: 'app_space_calendar_time_off', methods: ['GET', 'POST'])]
    #[IsGranted(SpaceVoter::MANAGE_CALENDAR, 'calendar')]
    public function timeOff(Calendar $calendar, Request $request, TimeOffRepository $timeOffs, ClockInterface $clock): Response
    {
        $timeOff = (new TimeOff())->setCalendar($calendar);
        $form = $this->createForm(TimeOffType::class, $timeOff, ['timezone' => $calendar->getCompany()->getTimezone()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($timeOff);
            $this->entityManager->flush();
            $this->addFlash('success', 'La fermeture est ajoutée : ses créneaux ne sont plus proposés.');

            return $this->redirectToRoute('app_space_calendar_time_off', ['id' => $calendar->getId()]);
        }

        return $this->render('space/calendars/time_off.html.twig', [
            'calendar' => $calendar,
            'form' => $form,
            'time_offs' => $timeOffs->findCurrentAndUpcoming($calendar, $clock->now()),
        ]);
    }

    #[Route('/{id<\d+>}/fermetures/{timeOff<\d+>}/supprimer', name: 'app_space_calendar_time_off_delete', methods: ['POST'])]
    #[IsGranted(SpaceVoter::MANAGE_CALENDAR, 'calendar')]
    public function deleteTimeOff(Calendar $calendar, #[MapEntity(id: 'timeOff')] TimeOff $timeOff, Request $request): Response
    {
        if ($timeOff->getCalendar() !== $calendar) {
            throw $this->createNotFoundException();
        }
        $this->checkToken($request, 'delete-time-off-'.$timeOff->getId());

        $this->entityManager->remove($timeOff);
        $this->entityManager->flush();
        $this->addFlash('success', 'La fermeture est supprimée.');

        return $this->redirectToRoute('app_space_calendar_time_off', ['id' => $calendar->getId()]);
    }

    #[Route('/{id<\d+>}/integration', name: 'app_space_calendar_embed', methods: ['GET'])]
    #[IsGranted(SpaceVoter::MANAGE_CALENDAR, 'calendar')]
    public function embed(Calendar $calendar): Response
    {
        return $this->render('space/calendars/embed.html.twig', ['calendar' => $calendar]);
    }

    /** Un calendrier qui a déjà des rendez-vous se ferme plutôt que de disparaître avec leur historique */
    #[Route('/{id<\d+>}/supprimer', name: 'app_space_calendar_delete', methods: ['POST'])]
    #[IsGranted('ROLE_COMPANY_OWNER')]
    #[IsGranted(SpaceVoter::MANAGE_CALENDAR, 'calendar')]
    public function delete(Calendar $calendar, Request $request, BookingRepository $bookings): Response
    {
        $this->checkToken($request, 'delete-calendar-'.$calendar->getId());

        if ($bookings->countForCalendar($calendar) > 0) {
            $this->addFlash('warning', sprintf('« %s » a des rendez-vous : il ne peut pas être supprimé. Décochez « Ouvert à la réservation » pour le fermer.', $calendar->getName()));

            return $this->redirectToRoute('app_space_calendar_edit', ['id' => $calendar->getId()]);
        }

        $this->entityManager->remove($calendar);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Le calendrier « %s » est supprimé.', $calendar->getName()));

        return $this->redirectToRoute('app_space_calendars');
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
