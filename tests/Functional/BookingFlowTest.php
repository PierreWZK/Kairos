<?php

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\Calendar;
use App\Enum\BookingStatus;
use App\Enum\CompanyRole;
use App\Repository\BookingRepository;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le parcours client par le lien de l'entreprise, comme un navigateur : choisir, réserver, recevoir les e-mails, annuler.
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
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheCompanyPageListsItsOpenCalendars(): void
    {
        $julie = $this->createAgenda();
        $this->createCalendar($julie->getCompany(), [], 'marc', static fn (Calendar $c) => $c->setIsActive(false));

        $this->client->request('GET', '/salon-lumiere');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Salon Lumière');
        self::assertSelectorTextContains('.services', 'Julie');
        self::assertSelectorTextNotContains('.services', 'Marc');
    }

    public function testTheCalendarPageListsOnlyTheServicesItOffers(): void
    {
        $calendar = $this->createAgenda();
        $company = $calendar->getCompany();
        $calendar->addService($this->createService($company, 'Appel découverte', 30, 0));
        $calendar->addService($this->createService($company, 'Atelier collectif', 120, active: false));
        $this->createService($company, 'Couleur', 90); // au catalogue, mais pas proposée sur ce calendrier
        $this->entityManager()->flush();

        $this->client->request('GET', '/salon-lumiere/julie');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.services', 'Séance individuelle');
        self::assertSelectorTextContains('.services', 'Gratuit');
        self::assertSelectorTextNotContains('.services', 'Atelier collectif');
        self::assertSelectorTextNotContains('.services', 'Couleur');
    }

    /** Ce qui n'est pas public donne une 404, y compris un id d'une autre entreprise glissé dans l'adresse */
    public function testWhatIsNotPublicGivesA404(): void
    {
        $calendar = $this->createAgenda();
        $service = $calendar->getServices()->first();
        $other = $this->createCompany('Cabinet Équilibre', 'cabinet-equilibre');
        $otherService = $this->createService($other, 'Séance secrète');
        $this->createCalendar($other, [$otherService], 'consultations');
        $suspended = $this->createCompany('Suspendue', 'suspendue');
        $suspended->setIsSuspended(true);
        $this->entityManager()->flush();

        foreach ([
            '/inconnue',
            '/suspendue',
            '/salon-lumiere/inconnu',
            '/salon-lumiere/julie/'.$otherService->getId(),
            '/cabinet-equilibre/consultations/'.$service->getId(),
            '/cabinet-equilibre/julie/'.$service->getId(),
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testACustomerBooksAFreeSlot(): void
    {
        $calendar = $this->createAgenda();
        $owner = $this->createMember($calendar->getCompany());
        $member = $this->createMember($calendar->getCompany(), CompanyRole::Member, 'marc@kairos.test');
        $service = $calendar->getServices()->first();

        $crawler = $this->client->request('GET', sprintf('/salon-lumiere/julie/%d?semaine=%s', $service->getId(), self::NEXT_MONDAY));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.planner-week', '26 octobre');

        $this->client->click($crawler->filter(sprintf('a.slot-free[href*="debut=%sT10:00"]', self::NEXT_MONDAY))->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.summary', 'Lundi 26 octobre 2026');
        self::assertSelectorTextContains('.summary', '10:00 → 11:00');

        $this->client->submitForm('Confirmer le rendez-vous', [
            'booking[customerName]' => 'Léa Dubois',
            'booking[customerEmail]' => 'lea@example.com',
            'booking[note]' => 'Première séance',
        ]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/rendez-vous/', $this->client->getResponse()->headers->get('Location'));

        // Confirmation au client (avec l'invitation .ics) et notification à l'entreprise : le propriétaire, pas le
        // membre qui ne gère pas ce calendrier
        self::assertEmailCount(2);
        $toCustomer = self::getMailerMessage(0);
        self::assertEmailAddressContains($toCustomer, 'To', 'lea@example.com');
        self::assertEmailHeaderSame($toCustomer, 'Subject', 'Votre rendez-vous est confirmé');
        self::assertEmailAttachmentCount($toCustomer, 1);
        self::assertEmailHtmlBodyContains($toCustomer, '10:00 → 11:00');
        $toCompany = self::getMailerMessage(1);
        self::assertEmailAddressContains($toCompany, 'To', $owner->getEmail());
        self::assertEmailAddressNotContains($toCompany, 'To', $member->getEmail());
        self::assertEmailAddressContains($toCompany, 'Reply-To', 'lea@example.com');

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'À bientôt, Léa Dubois !');
        self::assertSelectorTextContains('.summary', '10:00 → 11:00');

        $booking = $this->findBooking('Léa Dubois');
        self::assertSame($calendar->getId(), $booking->getCalendar()->getId());
        self::assertSame('Première séance', $booking->getNote());
    }

    public function testWithManualApprovalTheCustomerSendsARequest(): void
    {
        $calendar = $this->createAgenda(static fn (Calendar $c) => $c->setRequiresApproval(true));
        $this->createMember($calendar->getCompany());
        $service = $calendar->getServices()->first();

        $this->client->request('GET', sprintf('/salon-lumiere/julie/%d/creneau?debut=%sT10:00', $service->getId(), self::NEXT_MONDAY));
        $this->client->submitForm('Envoyer ma demande', [
            'booking[customerName]' => 'Léa Dubois',
            'booking[customerEmail]' => 'lea@example.com',
        ]);

        self::assertSame(BookingStatus::Pending, $this->findBooking('Léa Dubois')->getStatus());
        self::assertEmailCount(2);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'Subject', 'Votre demande de rendez-vous est bien reçue');
        self::assertEmailAttachmentCount(self::getMailerMessage(0), 0);
        self::assertEmailSubjectContains(self::getMailerMessage(1), 'Demande à valider');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.page-lede', 'en attente de validation');
    }

    public function testTheFormFollowsTheCalendarOptions(): void
    {
        $calendar = $this->createAgenda(static fn (Calendar $c) => $c
            ->setPhoneRequired(true)
            ->setNotesEnabled(false)
            ->setFormMessage('Merci d\'arriver 5 minutes en avance.'));
        $service = $calendar->getServices()->first();

        $this->client->request('GET', sprintf('/salon-lumiere/julie/%d/creneau?debut=%sT10:00', $service->getId(), self::NEXT_MONDAY));
        self::assertSelectorTextContains('.form-message', 'arriver 5 minutes en avance');
        self::assertSelectorNotExists('textarea[name="booking[note]"]');

        $this->client->submitForm('Confirmer le rendez-vous', [
            'booking[customerName]' => 'Léa Dubois',
            'booking[customerEmail]' => 'lea@example.com',
            'booking[customerPhone]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Indiquez un numéro de téléphone.');
    }

    public function testInvalidDetailsAreReportedInFrench(): void
    {
        $calendar = $this->createAgenda();
        $this->client->request('GET', sprintf('/salon-lumiere/julie/%d/creneau?debut=%sT10:00', $calendar->getServices()->first()->getId(), self::NEXT_MONDAY));

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
        $calendar = $this->createAgenda();
        $this->client->request('GET', sprintf('/salon-lumiere/julie/%d/creneau?debut=%sT10:00', $calendar->getServices()->first()->getId(), self::NEXT_MONDAY));

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
        $calendar = $this->createAgenda();
        $service = $calendar->getServices()->first();
        $this->createBooking($calendar, $service, self::NEXT_MONDAY.' 10:00');

        $this->client->request('GET', sprintf('/salon-lumiere/julie/%d/creneau?debut=%sT10:00', $service->getId(), self::NEXT_MONDAY));

        self::assertResponseRedirects(sprintf('/salon-lumiere/julie/%d?semaine=%s', $service->getId(), self::NEXT_MONDAY));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'n\'est plus disponible');
    }

    public function testACustomerCancelsFromTheirPrivatePage(): void
    {
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00');

        $this->client->request('GET', '/rendez-vous/'.$booking->getCancelToken());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');
        self::assertSelectorTextContains('.summary', '10:00 → 11:00');

        $this->client->submitForm('Oui, annuler le rendez-vous');

        self::assertResponseRedirects('/rendez-vous/'.$booking->getCancelToken());
        self::assertEmailCount(1); // l'entreprise n'a pas encore de membre dans ce test
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'Subject', 'Votre rendez-vous est annulé');

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Ce rendez-vous a été annulé');
        self::assertTrue($this->findBooking('Camille Martin')->isCancelled());
    }

    public function testCancellingTooLateIsRefused(): void
    {
        // Il est 08:00 : un rendez-vous à 09:30 commence dans moins de 2 h
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), '2026-10-19 09:30');

        $this->client->request('GET', '/rendez-vous/'.$booking->getCancelToken());
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

    public function testDatesAreShownInTheCompanyTimezone(): void
    {
        // Une entreprise de Montréal, ouverte le matin seulement (9 h-12 h, heure de Montréal). 9 h à Montréal,
        // c'est 14 h à Paris le 26 octobre : affichées à tort à l'heure de Paris, les heures commenceraient à 14:00
        $company = $this->createCompany('Studio Érable', 'studio-erable', 'America/Montreal');
        $calendar = $this->createCalendar($company, [$this->createService($company)], 'atelier', static function (Calendar $c): void {
            foreach ($c->getOpeningHours() as $hour) {
                if ('14:00' === $hour->getStartTime()->format('H:i')) {
                    $c->removeOpeningHour($hour);
                }
            }
        });
        $service = $calendar->getServices()->first();

        $this->client->request('GET', sprintf('/studio-erable/atelier/%d?semaine=%s', $service->getId(), self::NEXT_MONDAY));

        self::assertSelectorExists(sprintf('a.slot-free[href*="debut=%sT09:00"]', self::NEXT_MONDAY));
        self::assertSelectorTextContains('.planner-day', '09:00');
        self::assertSelectorNotExists(sprintf('a.slot-free[href*="debut=%sT14:00"]', self::NEXT_MONDAY));
    }

    private function findBooking(string $customerName): ?Booking
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(BookingRepository::class)->findOneBy(['customerName' => $customerName]);
    }
}
