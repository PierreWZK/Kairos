<?php

namespace App\Controller;

use App\Form\InvitationType;
use App\Repository\UserRepository;
use App\Security\AccountLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accepter une invitation dans l'équipe d'une entreprise : le lien signé de l'e-mail (voir AccountLinks)
 * mène ici, l'invité choisit son mot de passe et entre dans l'espace.
 */
final class InvitationController extends AbstractController
{
    #[Route('/invitation/{id<\d+>}', name: 'app_invitation', methods: ['GET', 'POST'])]
    public function accept(
        int $id,
        Request $request,
        UserRepository $users,
        AccountLinks $links,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        Security $security,
    ): Response {
        $user = $users->find($id);
        if (null === $user || !$links->isValidInvitation($request, $user) || true === $user->getCompany()?->isSuspended()) {
            return $this->render('security/invitation_failed.html.twig', [], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        $form = $this->createForm(InvitationType::class, ['name' => $user->getName()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user
                ->setName($form->get('name')->getData())
                ->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()))
                // Le lien est arrivé dans sa boîte : l'adresse est prouvée
                ->setIsVerified(true);
            $entityManager->flush();

            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', sprintf('Bienvenue dans l\'équipe de %s !', $user->getCompany()?->getName()));

            return $this->redirectToRoute('app_space');
        }

        return $this->render('security/invitation.html.twig', ['form' => $form, 'user' => $user]);
    }
}
