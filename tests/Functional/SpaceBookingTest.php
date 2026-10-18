<?php

namespace App\Tests\Functional;

use App\Entity\Calendar;
use App\Enum\BookingStatus;
use App\Enum\CompanyRole;
use App\Repository\BookingRepository;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les rendez-vous dans l'espace entreprise : liste filtrée, accepter ou refuser une demande, annuler.
 */
final class SpaceBookingTest extends WebTestCase
{
    use AgendaTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->freezeTime();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheListIsFilteredByPeriodStatusAndCalendar(): void
    {
        $julie = $this->createAgenda();
        $service = $julie->getServices()->first();
        $marc = $this->createCalendar($julie->getCompany(), [$service], 'marc');
        $this->createBooking($julie, $service, self::NEXT_MONDAY.' 10:00', 'Inès Moreau');
        $this->createBooking($marc, $service, self::NEXT_MONDAY.' 11:00', 'Nathan Petit');
        $this->createBooking($julie, $service, self::NEXT_MONDAY.' 14:00', 'Annulée Avant')->cancel();
        $this->createBooking($julie, $service, '2026-10-12 10:00', 'Client Passé');
        $this->entityManager()->flush();
        $this->client->loginUser($this->createMember($julie->getCompany()));

        $this->client->request('GET', '/espace/rendez-vous');
        self::assertSelectorTextContains('.booking-list', 'Inès Moreau');
        self::assertSelectorTextContains('.booking-list', 'Nathan Petit');
        self::assertSelectorTextContains('.booking-list', 'Lundi 26 octobre 2026');
        self::assertSelectorTextNotContains('.booking-list', 'Client Passé');

        $this->client->request('GET', '/espace/rendez-vous?periode=passes');
        self::assertSelectorTextContains('.booking-list', 'Client Passé');
        self::assertSelectorTextNotContains('.booking-list', 'Inès Moreau');

        $this->client->request('GET', '/espace/rendez-vous?statut=cancelled');
        self::assertSelectorTextContains('.booking-list', 'Annulée Avant');
        self::assertSelectorTextNotContains('.booking-list', 'Inès Moreau');

        $this->client->request('GET', sprintf('/espace/rendez-vous?calendrier=%d', $marc->getId()));
        self::assertSelectorTextContains('.booking-list', 'Nathan Petit');
        self::assertSelectorTextNotContains('.booking-list', 'Inès Moreau');
    }

    public function testAMemberOnlySeesTheBookingsOfTheirCalendars(): void
    {
        $julie = $this->createAgenda();
        $service = $julie->getServices()->first();
        $marc = $this->createCalendar($julie->getCompany(), [$service], 'marc');
        $hidden = $this->createBooking($julie, $service, self::NEXT_MONDAY.' 10:00', 'Inès Moreau');
        $this->createBooking($marc, $service, self::NEXT_MONDAY.' 11:00', 'Nathan Petit');
        $member = $this->createMember($julie->getCompany(), CompanyRole::Member, 'marc@kairos.test');
        $member->addCalendar($marc);
        $this->entityManager()->flush();
        $this->client->loginUser($member);

        // Même en forçant le filtre sur un calendrier qui n'est pas le sien
        $this->client->request('GET', sprintf('/espace/rendez-vous?calendrier=%d', $julie->getId()));
        self::assertSelectorTextContains('.booking-list', 'Nathan Petit');
        self::assertSelectorTextNotContains('.booking-list', 'Inès Moreau');

        $this->client->request('GET', sprintf('/espace/rendez-vous/%d', $hidden->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAcceptingARequestConfirmsItAndSendsTheInvite(): void
    {
        $calendar = $this->createAgenda(static fn (Calendar $c) => $c->setRequiresApproval(true));
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00');
        $booking->markPending();
        $this->entityManager()->flush();
        $this->client->loginUser($this->createMember($calendar->getCompany()));

        $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('.space-cards', '1 demande à valider');

        $crawler = $this->client->request('GET', sprintf('/espace/rendez-vous/%d', $booking->getId()));
        $this->client->submit($crawler->selectButton('Accepter')->form());

        self::assertResponseRedirects(sprintf('/espace/rendez-vous/%d', $booking->getId()));
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailHeaderSame($email, 'Subject', 'Votre rendez-vous est confirmé');
        self::assertEmailAttachmentCount($email, 1);
        self::assertSame(BookingStatus::Confirmed, $this->reload($booking->getId())->getStatus());
    }

    public function testRejectingARequestFreesTheSlotAndWarnsTheCustomer(): void
    {
        $calendar = $this->createAgenda(static fn (Calendar $c) => $c->setRequiresApproval(true));
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00');
        $booking->markPending();
        $this->entityManager()->flush();
        $this->client->loginUser($this->createMember($calendar->getCompany()));

        $crawler = $this->client->request('GET', sprintf('/espace/rendez-vous/%d', $booking->getId()));
        $this->client->submit($crawler->selectButton('Refuser')->form());

        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'Subject', 'Votre demande de rendez-vous n\'a pas pu être acceptée');
        self::assertSame(BookingStatus::Rejected, $this->reload($booking->getId())->getStatus());

        $this->client->request('GET', sprintf('/salon-lumiere/julie/%d?semaine=%s', $calendar->getServices()->first()->getId(), self::NEXT_MONDAY));
        self::assertSelectorExists(sprintf('a.slot-free[href*="debut=%sT10:00"]', self::NEXT_MONDAY));
    }

    public function testTheCompanyCancelsEvenShortlyBeforeTheAppointment(): void
    {
        // 09:00 : moins de 2 h après « maintenant » (08:00), trop tard pour le client, pas pour l'entreprise
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), '2026-10-19 09:00');
        $this->client->loginUser($this->createMember($calendar->getCompany()));

        $crawler = $this->client->request('GET', sprintf('/espace/rendez-vous/%d', $booking->getId()));
        $this->client->submit($crawler->selectButton('Oui, annuler le rendez-vous')->form());

        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(0), 'annulé de notre côté');
        self::assertTrue($this->reload($booking->getId())->isCancelled());
    }

    public function testNoDecisionOnAFinishedAppointment(): void
    {
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), '2026-10-12 10:00');
        $this->client->loginUser($this->createMember($calendar->getCompany()));

        $this->client->request('GET', sprintf('/espace/rendez-vous/%d', $booking->getId()));
        self::assertSelectorTextContains('.details-note', 'Ce rendez-vous est terminé');
        self::assertSelectorNotExists('.cancel-box');

        $this->client->request('POST', sprintf('/espace/rendez-vous/%d/annuler', $booking->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnotherCompanyCannotTouchTheBooking(): void
    {
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00');
        $this->client->loginUser($this->createMember($this->createCompany('Cabinet Équilibre', 'cabinet-equilibre'), email: 'contact@equilibre.test'));

        $this->client->request('GET', sprintf('/espace/rendez-vous/%d', $booking->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', sprintf('/espace/rendez-vous/%d/annuler', $booking->getId()));
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($booking->getId())->isCancelled());
    }

    private function reload(int $id): \App\Entity\Booking
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(BookingRepository::class)->find($id);
    }
}
