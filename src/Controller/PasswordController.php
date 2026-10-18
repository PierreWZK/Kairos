<?php

namespace App\Controller;

use App\Form\NewPasswordType;
use App\Notification\AccountMailer;
use App\Repository\UserRepository;
use App\Security\AccountLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mot de passe oublié : un lien signé par e-mail, valable une heure et une seule fois (voir AccountLinks).
 */
final class PasswordController extends AbstractController
{
    /**
     * La réponse est la même que l'adresse ait un compte ou non : la page ne révèle pas qui est inscrit.
     */
    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $users,
        AccountMailer $mailer,
        #[Target('password_reset.limiter')] RateLimiterFactoryInterface $limiter,
    ): Response {
        $form = $this->createFormBuilder()
            ->add('email', EmailType::class, [
                'label' => 'Votre adresse e-mail',
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [new Assert\NotBlank(message: 'Indiquez votre adresse e-mail.'), new Assert\Email()],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $form->get('email')->getData()));

            if (!$limiter->create($email.'|'.$request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError('Trop de demandes pour cette adresse : réessayez dans un quart d\'heure.'));

                return $this->render('security/forgot_password.html.twig', ['form' => $form], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            $user = $users->findOneBy(['email' => $email]);
            if (null !== $user) {
                $mailer->sendPasswordReset($user);
            }

            return $this->render('security/forgot_password_sent.html.twig', ['email' => $email]);
        }

        return $this->render('security/forgot_password.html.twig', ['form' => $form]);
    }

    #[Route('/mot-de-passe-oublie/{id<\d+>}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        int $id,
        Request $request,
        UserRepository $users,
        AccountLinks $links,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $users->find($id);
        if (null === $user || !$links->isValidReset($request, $user)) {
            return $this->render('security/reset_failed.html.twig', [], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        $form = $this->createForm(NewPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            // Le lien est arrivé dans sa boîte : l'adresse est prouvée
            $user->setIsVerified(true);
            $entityManager->flush();

            $this->addFlash('success', 'Votre mot de passe est changé. Vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', ['form' => $form]);
    }
}
