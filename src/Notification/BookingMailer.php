<?php

namespace App\Notification;

use App\Entity\Booking;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les e-mails d'un rendez-vous : au client (confirmation, annulation) et au professionnel (notification).
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
        #[Autowire(env: 'OWNER_EMAIL')]
        private readonly string $ownerEmail,
        #[Autowire(param: 'app.booking.cancel_notice_hours')]
        private readonly int $cancelNoticeHours,
    ) {
    }

    public function sendConfirmation(Booking $booking): void
    {
        $manageUrl = $this->manageUrl($booking);

        $this->send(
            $this->toCustomer($booking, 'Votre rendez-vous est confirmé', 'emails/customer_confirmation.html.twig')
                ->attach(CalendarInvite::forBooking($booking, $manageUrl), 'rendez-vous.ics', 'text/calendar; charset=utf-8; method=PUBLISH'),
            $booking,
        );
        $this->send($this->toOwner($booking, 'Nouveau rendez-vous', 'emails/owner_new.html.twig'), $booking);
    }

    public function sendCancellation(Booking $booking): void
    {
        $this->send($this->toCustomer($booking, 'Votre rendez-vous est annulé', 'emails/customer_cancellation.html.twig'), $booking);
        $this->send($this->toOwner($booking, 'Rendez-vous annulé', 'emails/owner_cancellation.html.twig'), $booking);
    }

    private function toCustomer(Booking $booking, string $subject, string $template): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->to(new Address($booking->getCustomerEmail(), $booking->getCustomerName()))
            ->subject($subject)
            ->htmlTemplate($template)
            ->context([
                'booking' => $booking,
                'manage_url' => $this->manageUrl($booking),
                'cancel_notice_hours' => $this->cancelNoticeHours,
            ]);
    }

    private function toOwner(Booking $booking, string $subject, string $template): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->to($this->ownerEmail)
            // « Répondre » écrit directement au client
            ->replyTo(new Address($booking->getCustomerEmail(), $booking->getCustomerName()))
            ->subject(sprintf('%s · %s, %s', $subject, $booking->getCustomerName(), $booking->getStartsAt()->format('d/m H:i')))
            ->htmlTemplate($template)
            ->context(['booking' => $booking]);
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
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('E-mail du rendez-vous {id} non envoyé : {message}', [
                'id' => $booking->getId(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
