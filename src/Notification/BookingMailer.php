<?php

namespace App\Notification;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Twig\DisplayTimezone;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les e-mails d'un rendez-vous : au client (confirmation, annulation) et à l'entreprise (notification).
 *
 * L'entreprise, ce sont ses propriétaires et les membres qui gèrent le calendrier concerné.
 * Les e-mails sont rendus dans le fuseau de l'entreprise.
 *
 * Un e-mail qui ne part pas ne doit pas faire échouer la réservation ou l'annulation, déjà enregistrées :
 * l'erreur est journalisée, et le client garde l'adresse de sa page de rendez-vous à l'écran.
 */
final class BookingMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly DisplayTimezone $displayTimezone,
    ) {
    }

    public function sendConfirmation(Booking $booking): void
    {
        $pending = BookingStatus::Pending === $booking->getStatus();
        $this->sendCustomerConfirmation($booking);
        $this->sendToCompany($booking, $pending ? 'Demande à valider' : 'Nouveau rendez-vous', 'emails/owner_new.html.twig');
    }

    /** L'entreprise a accepté la demande : le client reçoit la confirmation, avec l'invitation d'agenda */
    public function sendAccepted(Booking $booking): void
    {
        $this->sendCustomerConfirmation($booking);
    }

    /** L'entreprise a refusé la demande */
    public function sendRejected(Booking $booking): void
    {
        $this->send($this->toCustomer($booking, 'Votre demande de rendez-vous n\'a pas pu être acceptée', 'emails/customer_rejected.html.twig'), $booking);
    }

    private function sendCustomerConfirmation(Booking $booking): void
    {
        $pending = BookingStatus::Pending === $booking->getStatus();
        $email = $this->toCustomer(
            $booking,
            $pending ? 'Votre demande de rendez-vous est bien reçue' : 'Votre rendez-vous est confirmé',
            'emails/customer_confirmation.html.twig',
        );

        // L'invitation d'agenda n'a de sens qu'une fois le rendez-vous confirmé
        if (!$pending) {
            $email->attach(CalendarInvite::forBooking($booking, $this->manageUrl($booking)), 'rendez-vous.ics', 'text/calendar; charset=utf-8; method=PUBLISH');
        }

        $this->send($email, $booking);
    }

    /**
     * @param bool $byCompany true quand l'entreprise annule elle-même : le client est prévenu que l'annulation
     *                        vient d'elle, et l'entreprise ne reçoit pas de notification de son propre geste
     */
    public function sendCancellation(Booking $booking, bool $byCompany = false): void
    {
        $email = $this->toCustomer($booking, 'Votre rendez-vous est annulé', 'emails/customer_cancellation.html.twig');
        $email->context([...$email->getContext(), 'cancelled_by_owner' => $byCompany]);
        $this->send($email, $booking);

        if (!$byCompany) {
            $this->sendToCompany($booking, 'Rendez-vous annulé', 'emails/owner_cancellation.html.twig');
        }
    }

    private function toCustomer(Booking $booking, string $subject, string $template): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->to(new Address($booking->getCustomerEmail(), $booking->getCustomerName()))
            ->subject($subject)
            ->htmlTemplate($template)
            ->context([
                'booking' => $booking,
                'company' => $booking->getCompany(),
                'manage_url' => $this->manageUrl($booking),
            ]);
    }

    private function sendToCompany(Booking $booking, string $subject, string $template): void
    {
        $recipients = array_map(
            static fn (User $user): Address => new Address($user->getEmail(), $user->getName()),
            $this->companyRecipients($booking),
        );
        if ([] === $recipients) {
            return;
        }

        $local = $booking->getLocalStartsAt();
        $this->send((new TemplatedEmail())
            ->to(...$recipients)
            // « Répondre » écrit directement au client
            ->replyTo(new Address($booking->getCustomerEmail(), $booking->getCustomerName()))
            ->subject(sprintf('%s · %s, %s', $subject, $booking->getCustomerName(), $local->format('d/m H:i')))
            ->htmlTemplate($template)
            ->context(['booking' => $booking, 'company' => $booking->getCompany()]), $booking);
    }

    /** @return list<User> Les propriétaires de l'entreprise et les membres qui gèrent ce calendrier */
    private function companyRecipients(Booking $booking): array
    {
        $calendar = $booking->getCalendar();

        return array_values(array_filter(
            $booking->getCompany()->getUsers()->toArray(),
            static fn (User $user): bool => $user->manages($calendar),
        ));
    }

    private function manageUrl(Booking $booking): string
    {
        return $this->urlGenerator->generate(
            'app_booking_show',
            ['token' => $booking->getCancelToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function send(TemplatedEmail $email, Booking $booking): void
    {
        try {
            // Le rendu (à l'envoi) affiche les dates dans le fuseau de l'entreprise
            $this->displayTimezone->withCompany($booking->getCompany(), fn () => $this->mailer->send($email));
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('E-mail du rendez-vous {id} non envoyé : {message}', [
                'id' => $booking->getId(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
