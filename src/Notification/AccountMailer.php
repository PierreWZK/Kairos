<?php

namespace App\Notification;

use App\Entity\User;
use App\Security\AccountLinks;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Les e-mails d'un compte : vérification de l'adresse, nouveau mot de passe, invitation dans une équipe.
 *
 * Un échec d'envoi est journalisé sans interrompre la page : l'utilisateur peut redemander le lien.
 */
final class AccountMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AccountLinks $links,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendVerification(User $user): void
    {
        $this->send($user, 'Confirmez votre adresse e-mail', 'emails/account_verification.html.twig', $this->links->verificationUrl($user));
    }

    public function sendPasswordReset(User $user): void
    {
        $this->send($user, 'Choisissez un nouveau mot de passe', 'emails/account_reset.html.twig', $this->links->resetUrl($user));
    }

    /** Invitation dans l'équipe de l'entreprise, envoyée par $invitedBy */
    public function sendInvitation(User $user, User $invitedBy): void
    {
        $this->send(
            $user,
            sprintf('%s vous invite sur Kairos', $invitedBy->getName()),
            'emails/team_invitation.html.twig',
            $this->links->invitationUrl($user),
            ['invited_by' => $invitedBy],
        );
    }

    /** @param array<string, mixed> $context */
    private function send(User $user, string $subject, string $template, string $url, array $context = []): void
    {
        $email = (new TemplatedEmail())
            ->to(new Address($user->getEmail(), $user->getName()))
            ->subject($subject)
            ->htmlTemplate($template)
            ->context(['user' => $user, 'url' => $url, ...$context]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('E-mail « {subject} » non envoyé au compte {id} : {message}', [
                'subject' => $subject,
                'id' => $user->getId(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
