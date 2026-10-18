<?php

namespace App\Controller\Space;

use App\Form\Space\ChangePasswordType;
use App\Form\Space\ProfileType;
use App\Notification\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le compte de l'utilisateur : nom, adresse e-mail, mot de passe.
 * Ouvert même avant la vérification de l'adresse, pour corriger une adresse mal saisie.
 *
 * Changer d'adresse ou de mot de passe modifie ce que Symfony compare pour garder la session ouverte :
 * l'utilisateur est reconnecté aussitôt, sans quoi il serait déconnecté à la page suivante.
 */
#[IsGranted('ROLE_COMPANY_MEMBER')]
final class AccountController extends AbstractSpaceController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    #[Route('/espace/compte', name: 'app_space_account', methods: ['GET', 'POST'])]
    public function edit(Request $request, UserPasswordHasherInterface $passwordHasher, AccountMailer $mailer): Response
    {
        $user = $this->user();
        $previousEmail = $user->getEmail();

        $profile = $this->createForm(ProfileType::class, $user);
        $password = $this->createForm(ChangePasswordType::class);
        $profile->handleRequest($request);
        $password->handleRequest($request);

        if ($profile->isSubmitted()) {
            $emailChanged = $user->getEmail() !== $previousEmail;
            if ($emailChanged && !$passwordHasher->isPasswordValid($user, (string) $profile->get('currentPassword')->getData())) {
                $profile->get('currentPassword')->addError(new FormError('Indiquez votre mot de passe actuel pour changer d\'adresse.'));
            }

            if ($profile->isValid()) {
                if ($emailChanged) {
                    $user->setIsVerified(false);
                }
                $this->entityManager->flush();
                if ($emailChanged) {
                    $mailer->sendVerification($user);
                    $this->security->login($user, 'form_login', 'main');
                }
                $this->addFlash('success', $emailChanged
                    ? sprintf('Votre adresse est changée : confirmez-la avec le lien envoyé à %s.', $user->getEmail())
                    : 'Votre compte est enregistré.');

                return $this->redirectToRoute('app_space_account');
            }

            // Refusé : l'utilisateur connecté reprend ses valeurs enregistrées
            $view = $profile->createView();
            $this->entityManager->refresh($user);

            return $this->renderPage($view, $password->createView(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($password->isSubmitted()) {
            // Compte de démonstration public : le formulaire n'est pas affiché, et une requête forgée est refusée
            if ($this->company()->isDemo()) {
                throw $this->createAccessDeniedException('Le mot de passe d\'un compte de démonstration ne change pas.');
            }
            if ($password->isValid()) {
                $user->setPassword($passwordHasher->hashPassword($user, $password->get('plainPassword')->getData()));
                $this->entityManager->flush();
                $this->security->login($user, 'form_login', 'main');
                $this->addFlash('success', 'Votre mot de passe est changé.');

                return $this->redirectToRoute('app_space_account');
            }

            return $this->renderPage($profile->createView(), $password->createView(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderPage($profile->createView(), $password->createView());
    }

    private function renderPage(mixed $profile, mixed $password, int $status = Response::HTTP_OK): Response
    {
        return $this->render('space/account.html.twig', [
            'company' => $this->company(),
            'profile' => $profile,
            'password' => $password,
        ], new Response(status: $status));
    }
}
