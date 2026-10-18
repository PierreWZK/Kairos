<?php

namespace App\Controller\Space;

use App\Entity\Service;
use App\Form\Space\ServiceType;
use App\Repository\BookingRepository;
use App\Security\Voter\SpaceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le catalogue de prestations de l'entreprise, géré par le propriétaire.
 * Chaque calendrier coche ensuite celles qu'il propose.
 */
#[IsGranted('ROLE_COMPANY_OWNER')]
#[Route('/espace/prestations')]
final class ServiceController extends AbstractSpaceController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_space_services', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('space/services/index.html.twig', ['services' => $this->company()->getServices()]);
    }

    #[Route('/nouvelle', name: 'app_space_service_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->company();
        $service = (new Service())->setCompany($company);
        $form = $this->createForm(ServiceType::class, $service);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $company->addService($service);
            // Proposée d'office sur chaque calendrier ; à décocher dans les réglages d'un calendrier si besoin
            foreach ($company->getCalendars() as $calendar) {
                $calendar->addService($service);
            }
            $this->entityManager->persist($service);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('La prestation « %s » est ajoutée à vos calendriers.', $service->getName()));

            return $this->redirectToRoute('app_space_services');
        }

        return $this->render('space/services/form.html.twig', ['form' => $form, 'service' => null]);
    }

    #[Route('/{id<\d+>}', name: 'app_space_service_edit', methods: ['GET', 'POST'])]
    #[IsGranted(SpaceVoter::MANAGE_SERVICE, 'service')]
    public function edit(Service $service, Request $request): Response
    {
        $form = $this->createForm(ServiceType::class, $service);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'La prestation est enregistrée.');

            return $this->redirectToRoute('app_space_services');
        }

        return $this->render('space/services/form.html.twig', ['form' => $form, 'service' => $service]);
    }

    /** Une prestation déjà réservée se désactive plutôt que de disparaître des rendez-vous */
    #[Route('/{id<\d+>}/supprimer', name: 'app_space_service_delete', methods: ['POST'])]
    #[IsGranted(SpaceVoter::MANAGE_SERVICE, 'service')]
    public function delete(Service $service, Request $request, BookingRepository $bookings): Response
    {
        if (!$this->isCsrfTokenValid('delete-service-'.$service->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($bookings->countForService($service) > 0) {
            $this->addFlash('warning', sprintf('« %s » a déjà été réservée : elle ne peut pas être supprimée. Décochez « Réservable » pour la retirer des pages de réservation.', $service->getName()));

            return $this->redirectToRoute('app_space_service_edit', ['id' => $service->getId()]);
        }

        foreach ($service->getCompany()->getCalendars() as $calendar) {
            $calendar->removeService($service);
        }
        $this->entityManager->remove($service);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('La prestation « %s » est supprimée.', $service->getName()));

        return $this->redirectToRoute('app_space_services');
    }
}
