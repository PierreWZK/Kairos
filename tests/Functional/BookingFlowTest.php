<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Repository\BookingRepository;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le parcours client, comme un navigateur : choisir, réserver, recevoir les e-mails, annuler.
 */
final class BookingFlowTest extends WebTestCase
{
    use AgendaTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le CSRF « stateless » de Symfony vérifie l'origine de la requête, comme le ferait un navigateur
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->freezeTime();
        $this->createWeekdayHours();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testThePublicPagesAreReachable(): void
    {
        $this->createService('Appel découverte', 30, 0);
        $this->createService('Atelier collectif', 120, 3000, active: false);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le bon moment');

        $this->client->request('GET', '/reserver');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.services', 'Appel découverte');
        self::assertSelectorTextContains('.services', 'Gratuit');
        self::assertSelectorTextNotContains('.services', 'Atelier collectif');
    }

    public function testAnInactiveServiceCannotBeBooked(): void
    {
        $service = $this->createService('Atelier collectif', active: false);

        $this->client->request('GET', '/reserver/'.$service->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testACustomerBooksAFreeSlot(): void
    {
        $service = $this->createService();

        $crawler = $this->client->request('GET', sprintf('/reserver/%d?semaine=%s', $service->getId(), self::NEXT_MONDAY));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.planner-week', '26 octobre');

        $this->client->click($crawler->filter(sprintf('a.slot-free[href*="debut=%sT10:00"]', self::NEXT_MONDAY))->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.summary', 'Lundi 26 octobre 2026');

        $this->client->submitForm('Confirmer le rendez-vous', [
            'booking[customerName]' => 'Léa Dubois',
            'booking[customerEmail]' => 'lea@example.com',
            'booking[note]' => 'Première séance',
        ]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/rendez-vous/', $this->client->getResponse()->headers->get('Location'));

        // Confirmation au client (avec l'invitation .ics) et notification au professionnel
        self::assertEmailCount(2);
        $toCustomer = self::getMailerMessage(0);
        self::assertEmailAddressContains($toCustomer, 'To', 'lea@example.com');
        self::assertEmailHeaderSame($toCustomer, 'Subject', 'Votre rendez-vous est confirmé');
        self::assertEmailAttachmentCount($toCustomer, 1);
        self::assertEmailAddressContains(self::getMailerMessage(1), 'Reply-To', 'lea@example.com');

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'À bientôt, Léa Dubois !');

        $booking = $this->findBooking('Léa Dubois');
        self::assertEquals(new \DateTimeImmutable(self::NEXT_MONDAY.' 11:00'), $booking->getEndsAt());
        self::assertSame('Première séance', $booking->getNote());
    }

    public function testInvalidDetailsAreReportedInFrench(): void
    {
        $service = $this->createService();
        $this->client->request('GET', sprintf('/reserver/%d/creneau?debut=%sT10:00', $service->getId(), self::NEXT_MONDAY));

        $this->client->submitForm('Confirmer le rendez-vous', [
            'booking[customerName]' => '',
            'booking[customerEmail]' => 'pas-un-email',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Cette valeur ne doit pas être vide.');
        self::assertSelectorTextContains('.booking-form', 'pas une adresse e-mail valide');
        self::assertEmailCount(0);
    }

    public function testAFormPostedFromAnotherSiteIsRejected(): void
    {
        $service = $this->createService();
        $this->client->request('GET', sprintf('/reserver/%d/creneau?debut=%sT10:00', $service->getId(), self::NEXT_MONDAY));

        // Un formulaire piégé sur un autre site : l'origine et la page de provenance sont étrangères
        // (Symfony accepte la requête si l'une des deux correspond au site)
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://pirate.example');
        $this->client->setServerParameter('HTTP_REFERER', 'https://pirate.example/formulaire');
        $this->client->submitForm('Confirmer le rendez-vous', [
            'booking[customerName]' => 'Pirate',
            'booking[customerEmail]' => 'pirate@example.com',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'jeton CSRF est invalide');
        self::assertNull($this->findBooking('Pirate'));
    }

    public function testATakenSlotSendsTheCustomerBackToTheAgenda(): void
    {
        $service = $this->createService();
        $this->createBooking($service, self::NEXT_MONDAY.' 10:00');

        $this->client->request('GET', sprintf('/reserver/%d/creneau?debut=%sT10:00', $service->getId(), self::NEXT_MONDAY));

        self::assertResponseRedirects(sprintf('/reserver/%d?semaine=%s', $service->getId(), self::NEXT_MONDAY));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'n\'est plus disponible');
    }

    public function testACustomerCancelsFromTheirPrivatePage(): void
    {
        $booking = $this->createBooking($this->createService(), self::NEXT_MONDAY.' 10:00');

        $this->client->request('GET', '/rendez-vous/'.$booking->getCancelToken());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');

        $this->client->submitForm('Oui, annuler le rendez-vous');

        self::assertResponseRedirects('/rendez-vous/'.$booking->getCancelToken());
        self::assertEmailCount(2);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'Subject', 'Votre rendez-vous est annulé');

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Ce rendez-vous a été annulé');
        self::assertTrue($this->findBooking('Camille Martin')->isCancelled());
    }

    public function testCancellingLessThanTwoHoursBeforeIsRefused(): void
    {
        // Il est 08:00 : un rendez-vous à 09:30 commence dans moins de 2 h
        $booking = $this->createBooking($this->createService(), '2026-10-19 09:30');

        $crawler = $this->client->request('GET', '/rendez-vous/'.$booking->getCancelToken());
        self::assertSelectorTextContains('.details-note', 'ne peut plus être annulé en ligne');
        self::assertSelectorNotExists('.cancel-box');

        // Même en envoyant la requête directement, le serveur refuse
        $this->client->request('POST', '/rendez-vous/'.$booking->getCancelToken().'/annuler', ['_token' => 'csrf-token']);
        self::assertEmailCount(0);
        self::assertFalse($this->findBooking('Camille Martin')->isCancelled());
    }

    public function testAnUnknownTokenGivesA404(): void
    {
        $this->client->request('GET', '/rendez-vous/'.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
    }

    private function findBooking(string $customerName): ?Booking
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(BookingRepository::class)->findOneBy(['customerName' => $customerName]);
    }
}
