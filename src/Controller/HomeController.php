<?php

namespace App\Controller;

use App\Demo\DemoData;
use App\Entity\Company;
use App\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La vitrine de Kairos, tournée vers les entreprises, et ses démos.
 */
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companies,
    ) {
    }

    /** La vitrine n'affiche que les entreprises de démonstration qui existent, et leurs comptes publics */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        $demos = $this->companies->findBy(['isDemo' => true, 'isSuspended' => false], ['name' => 'DESC']);

        return $this->render('home/index.html.twig', [
            'demos' => $demos,
            'demo_accounts' => array_any($demos, static fn (Company $company): bool => 'salon-lumiere' === $company->getSlug()) ? DemoData::ACCOUNTS : [],
            'demo_password' => DemoData::DEMO_PASSWORD,
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
