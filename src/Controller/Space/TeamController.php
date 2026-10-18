<?php

namespace App\Controller\Space;

use App\Entity\User;
use App\Enum\CompanyRole;
use App\Form\Space\MemberType;
use App\Notification\AccountMailer;
use App\Security\Voter\SpaceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'équipe de l'entreprise, gérée par le propriétaire : inviter, attribuer les calendriers, retirer.
 *
 * Un invité a un compte sans mot de passe : il le choisit avec le lien reçu par e-mail (InvitationController).
 * Le propriétaire ne modifie jamais son propre compte ici (SpaceVoter) : l'entreprise garde toujours un propriétaire.
 */
#[IsGranted('ROLE_COMPANY_OWNER')]
#[Route('/espace/equipe')]
final class TeamController extends AbstractSpaceController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountMailer $mailer,
        #[Target('team_invitation.limiter')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('', name: 'app_space_team', methods: ['GET'])]
    public function index(): Response
    {
        $members = $this->company()->getUsers()->toArray();
        // Les propriétaires d'abord, puis par nom
        usort($members, static fn (User $a, User $b): int => [$b->isOwner(), $a->getName()] <=> [$a->isOwner(), $b->getName()]);

        return $this->render('space/team/index.html.twig', [
            'company' => $this->company(),
            'members' => $members,
        ]);
    }

    #[Route('/inviter', name: 'app_space_team_invite', methods: ['GET', 'POST'])]
    public function invite(Request $request): Response
    {
        $company = $this->company();
        $member = (new User())->setCompany($company)->setCompanyRole(CompanyRole::Member);
        $form = $this->createForm(MemberType::class, $member, ['invite' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->limiter->create((string) $company->getId())->consume()->isAccepted()) {
                $form->addError(new FormError('Beaucoup d\'invitations viennent d\'être envoyées : réessayez dans une heure.'));

                return $this->render('space/team/form.html.twig', ['company' => $company, 'form' => $form, 'member' => null], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            // Pas de mot de passe : le compte ne s'ouvre qu'avec le lien de l'invitation
            $member->setPassword('')->setIsVerified(false);
            $company->getUsers()->add($member);
            $this->entityManager->persist($member);
            $this->entityManager->flush();
            $this->mailer->sendInvitation($member, $this->user());
            $this->addFlash('success', sprintf('L\'invitation est envoyée à %s.', $member->getEmail()));

            return $this->redirectToRoute('app_space_team');
        }

        return $this->render('space/team/form.html.twig', ['company' => $company, 'form' => $form, 'member' => null]);
    }

    #[Route('/{id<\d+>}', name: 'app_space_team_edit', methods: ['GET', 'POST'])]
    #[IsGranted(SpaceVoter::MANAGE_MEMBER, 'member')]
    public function edit(User $member, Request $request): Response
    {
        $form = $this->createForm(MemberType::class, $member);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Le compte de %s est enregistré.', $member->getName()));

            return $this->redirectToRoute('app_space_team');
        }

        return $this->render('space/team/form.html.twig', ['company' => $this->company(), 'form' => $form, 'member' => $member]);
    }

    #[Route('/{id<\d+>}/renvoyer', name: 'app_space_team_resend', methods: ['POST'])]
    #[IsGranted(SpaceVoter::MANAGE_MEMBER, 'member')]
    public function resend(User $member, Request $request): Response
    {
        $this->checkToken('resend-invitation-'.$member->getId(), $request);

        if (!$member->isInvitationPending()) {
            $this->addFlash('warning', sprintf('%s a déjà accepté l\'invitation.', $member->getName()));
        } elseif (!$this->limiter->create((string) $this->company()->getId())->consume()->isAccepted()) {
            $this->addFlash('warning', 'Beaucoup d\'invitations viennent d\'être envoyées : réessayez dans une heure.');
        } else {
            $this->mailer->sendInvitation($member, $this->user());
            $this->addFlash('success', sprintf('L\'invitation est renvoyée à %s.', $member->getEmail()));
        }

        return $this->redirectToRoute('app_space_team');
    }

    /** Le compte est supprimé ; ses rendez-vous, rattachés aux calendriers, restent */
    #[Route('/{id<\d+>}/retirer', name: 'app_space_team_remove', methods: ['POST'])]
    #[IsGranted(SpaceVoter::MANAGE_MEMBER, 'member')]
    public function remove(User $member, Request $request): Response
    {
        $this->checkToken('remove-member-'.$member->getId(), $request);

        $this->company()->getUsers()->removeElement($member);
        $this->entityManager->remove($member);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('%s ne fait plus partie de l\'équipe.', $member->getName()));

        return $this->redirectToRoute('app_space_team');
    }

    private function checkToken(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
