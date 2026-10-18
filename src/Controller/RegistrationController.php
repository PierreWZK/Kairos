<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Enum\CompanyRole;
use App\Form\Model\Registration;
use App\Form\RegistrationType;
use App\Notification\AccountMailer;
use App\Repository\UserRepository;
use App\Security\AccountLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Inscription libre : une entreprise et son propriétaire, puis la vérification de l'adresse e-mail.
 */
final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        AccountMailer $mailer,
        Security $security,
    ): Response {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_space');
        }

        $registration = new Registration();
        $form = $this->createForm(RegistrationType::class, $registration);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $company = (new Company())
                ->setName($registration->companyName)
                ->setSlug($registration->companySlug)
                ->setTimezone($registration->timezone);

            $user = (new User())
                ->setName($registration->name)
                ->setEmail($registration->email)
                ->setCompany($company)
                ->setCompanyRole(CompanyRole::Owner);
            $user->setPassword($passwordHasher->hashPassword($user, $registration->plainPassword));

            $entityManager->persist($company);
            $entityManager->persist($user);
            $entityManager->flush();

            $mailer->sendVerification($user);
            $security->login($user, 'form_login', 'main');

            return $this->redirectToRoute('app_space');
        }

        return $this->render('security/register.html.twig', ['form' => $form]);
    }

    /** Le lien de l'e-mail : il fonctionne aussi sans être connecté (ouvert sur un autre appareil, par exemple) */
    #[Route('/verification/email/{id<\d+>}', name: 'app_verify_email', methods: ['GET'])]
    public function verify(int $id, Request $request, UserRepository $users, AccountLinks $links, EntityManagerInterface $entityManager): Response
    {
        $user = $users->find($id);
        if (null === $user || !$links->isValidVerification($request, $user)) {
            return $this->render('security/verification_failed.html.twig', [], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        if (!$user->isVerified()) {
            $user->setIsVerified(true);
            $entityManager->flush();
        }

        $this->addFlash('success', 'Votre adresse e-mail est confirmée. Bienvenue sur Kairos !');

        return $this->redirectToRoute($this->getUser() === $user ? 'app_space' : 'app_login');
    }

    #[Route('/verification/email/renvoyer', name: 'app_resend_verification', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function resend(
        Request $request,
        AccountMailer $mailer,
        #[Target('verification_email.limiter')] RateLimiterFactoryInterface $limiter,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('resend-verification', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($user->isVerified()) {
            return $this->redirectToRoute('app_space');
        }

        if (!$limiter->create((string) $user->getId())->consume()->isAccepted()) {
            $this->addFlash('warning', 'Plusieurs liens viennent de vous être envoyés : patientez quelques minutes avant d\'en redemander un.');
        } else {
            $mailer->sendVerification($user);
            $this->addFlash('success', sprintf('Un nouveau lien a été envoyé à %s.', $user->getEmail()));
        }

        return $this->redirectToRoute('app_space');
    }
}
