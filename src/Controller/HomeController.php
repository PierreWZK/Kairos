<?php

namespace App\Controller;

use App\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La vitrine de Kairos, tournée vers les entreprises, et ses démos.
 */
final class HomeController extends AbstractController
{
    /** Les entreprises de démonstration (fixtures) : la vitrine n'affiche que celles qui existent */
    private const array DEMO_SLUGS = ['salon-lumiere', 'cabinet-equilibre'];

    public function __construct(
        private readonly CompanyRepository $companies,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'demos' => $this->companies->findBy(['slug' => self::DEMO_SLUGS, 'isSuspended' => false], ['name' => 'DESC']),
        ]);
    }

    /** Le site (fictif) du Salon Lumière, qui intègre son calendrier Kairos avec le code de l'espace entreprise */
    #[Route('/demo/integration', name: 'app_demo_integration', methods: ['GET'])]
    public function integrationDemo(): Response
    {
        $company = $this->companies->findPublicBySlug('salon-lumiere') ?? throw $this->createNotFoundException('Les données de démonstration ne sont pas chargées.');

        return $this->render('home/integration_demo.html.twig', [
            'company' => $company,
        ]);
    }
}
