<?php

namespace App\Controller;

use App\Entity\Service;
use App\Repository\ServiceRepository;
use App\Scheduling\SlotFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reserver')]
final class BookingController extends AbstractController
{
    #[Route('', name: 'app_booking_services', methods: ['GET'])]
    public function services(ServiceRepository $services): Response
    {
        return $this->render('booking/services.html.twig', [
            'services' => $services->findActive(),
        ]);
    }

    #[Route('/{id<\d+>}', name: 'app_booking_agenda', methods: ['GET'])]
    public function agenda(Service $service, SlotFinder $slotFinder, #[MapQueryParameter] ?string $semaine = null): Response
    {
        if (!$service->isActive()) {
            throw $this->createNotFoundException('Cette prestation n\'est pas proposée.');
        }

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
}
