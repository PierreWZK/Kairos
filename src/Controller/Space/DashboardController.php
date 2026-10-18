<?php

namespace App\Controller\Space;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'espace entreprise (/espace) : là où arrive un membre d'entreprise après sa connexion.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class DashboardController extends AbstractController
{
    #[Route('/espace', name: 'app_space', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Le super-admin n'a pas d'entreprise : son espace, c'est EasyAdmin
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin');
        }

        $company = $user->getCompany() ?? throw $this->createAccessDeniedException('Ce compte n\'est rattaché à aucune entreprise.');

        return $this->render('space/dashboard.html.twig', [
            'user' => $user,
            'company' => $company,
        ]);
    }
}
