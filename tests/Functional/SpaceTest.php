<?php

namespace App\Tests\Functional;

use App\Entity\Calendar;
use App\Entity\Company;
use App\Entity\User;
use App\Enum\CompanyRole;
use App\Repository\CalendarRepository;
use App\Repository\CompanyRepository;
use App\Repository\ServiceRepository;
use App\Repository\TimeOffRepository;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'espace entreprise : calendriers (réglages, horaires, fermetures, intégration) et prestations,
 * avec les droits du propriétaire, du membre, et l'isolement entre entreprises.
 */
final class SpaceTest extends WebTestCase
{
    use AgendaTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le CSRF « stateless » des formulaires vérifie l'origine de la requête, comme le ferait un navigateur
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->freezeTime();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnUnverifiedOwnerOnlyReachesTheHomePage(): void
    {
        $owner = $this->createMember($this->createCompany());
        $owner->setIsVerified(false);
        $this->entityManager()->flush();
        $this->client->loginUser($owner);

        $this->client->request('GET', '/espace/calendriers');

        self::assertResponseRedirects('/espace');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'Confirmez d\'abord votre adresse e-mail');
    }

    public function testTheOwnerAddsAServiceProposedOnEveryCalendar(): void
    {
        $calendar = $this->createAgenda();
        $this->loginOwner($calendar->getCompany());

        $this->client->request('GET', '/espace/prestations/nouvelle');
        $this->client->submitForm('Ajouter la prestation', [
            'service[name]' => 'Soin du visage',
            'service[durationMinutes]' => '45',
            'service[priceCents]' => '45,50',
        ]);

        self::assertResponseRedirects('/espace/prestations');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.row-list', 'Soin du visage');
        self::assertSelectorTextContains('.row-list', '45,50');

        $service = self::getContainer()->get(ServiceRepository::class)->findOneBy(['name' => 'Soin du visage']);
        self::assertSame(4550, $service->getPriceCents());
        self::assertContains($service->getId(), $this->reloadCalendar($calendar)->getServices()->map(static fn ($s) => $s->getId())->toArray());
    }

    public function testTheOwnerCreatesACalendarReadyToBook(): void
    {
        $company = $this->createCompany();
        $this->createService($company, 'Coupe', 30, 2500);
        $this->createService($company, 'Ancienne', 30, active: false);
        $this->loginOwner($company);

        $this->client->request('GET', '/espace/calendriers/nouveau');
        $this->client->submitForm('Créer le calendrier', [
            'calendar_settings[name]' => 'Julie',
            'calendar_settings[slug]' => 'julie',
        ]);

        $calendar = self::getContainer()->get(CalendarRepository::class)->findOneBy(['slug' => 'julie']);
        self::assertResponseRedirects(sprintf('/espace/calendriers/%d/horaires', $calendar->getId()));
        // Ouvert du lundi au vendredi, 2 plages par jour ; seules les prestations réservables sont cochées
        self::assertCount(10, $calendar->getOpeningHours());
        self::assertSame(['Coupe'], array_map(static fn ($s) => $s->getName(), $calendar->getServices()->toArray()));

        $this->client->request('GET', '/salon-lumiere/julie');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.services', 'Coupe');
    }

    public function testTheSettingsDriveThePublicPages(): void
    {
        $calendar = $this->createAgenda();
        $other = $this->createService($calendar->getCompany(), 'Couleur', 90);
        $calendar->addService($other);
        $this->entityManager()->flush();
        $this->loginOwner($calendar->getCompany());

        $crawler = $this->client->request('GET', sprintf('/espace/calendriers/%d', $calendar->getId()));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['calendar_settings[slotStepMinutes]'] = '15';
        $form['calendar_settings[requiresApproval]']->tick();
        $form['calendar_settings[phoneRequired]']->tick();
        // Décoche « Couleur » : elle reste au catalogue, mais n'est plus proposée ici
        foreach ($crawler->filter('input[name="calendar_settings[services][]"]') as $i => $input) {
            if ((string) $other->getId() === $input->getAttribute('value')) {
                $form['calendar_settings[services]['.$i.']']->untick();
            }
        }
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/espace/calendriers/%d', $calendar->getId()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Les réglages sont enregistrés');

        $saved = $this->reloadCalendar($calendar);
        self::assertSame(15, $saved->getSlotStepMinutes());
        self::assertTrue($saved->requiresApproval());
        self::assertTrue($saved->isPhoneRequired());

        $this->client->request('GET', '/salon-lumiere/julie');
        self::assertSelectorTextNotContains('.services', 'Couleur');
    }

    public function testOpeningHoursAreReplacedAndChecked(): void
    {
        $calendar = $this->createAgenda();
        $this->loginOwner($calendar->getCompany());
        $url = sprintf('/espace/calendriers/%d/horaires', $calendar->getId());

        // Deux plages du mardi qui se chevauchent : refusé
        $this->postForm($url, 'opening_hours', ['openingHours' => [
            ['dayOfWeek' => '2', 'startTime' => '09:00', 'endTime' => '13:00'],
            ['dayOfWeek' => '2', 'startTime' => '12:00', 'endTime' => '18:00'],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Deux plages du mardi se chevauchent');

        // Une fin avant le début : refusé
        $this->postForm($url, 'opening_hours', ['openingHours' => [
            ['dayOfWeek' => '3', 'startTime' => '18:00', 'endTime' => '09:00'],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'L\'heure de fin doit être après l\'heure de début');

        // La nouvelle semaine remplace l'ancienne
        $this->postForm($url, 'opening_hours', ['openingHours' => [
            ['dayOfWeek' => '6', 'startTime' => '10:00', 'endTime' => '16:00'],
        ]]);
        self::assertResponseRedirects($url);

        $hours = $this->reloadCalendar($calendar)->getOpeningHours();
        self::assertCount(1, $hours);
        self::assertSame(6, $hours->first()->getDayOfWeek());
        self::assertSame('10:00', $hours->first()->getStartTime()->format('H:i'));
    }

    public function testTimeOffIsEnteredInTheCompanyTimezoneAndCanBeRemoved(): void
    {
        $company = $this->createCompany('Studio Nord', 'studio-nord', 'America/Montreal');
        $calendar = $this->createCalendar($company, [$this->createService($company)], 'studio');
        $this->loginOwner($company);
        $url = sprintf('/espace/calendriers/%d/fermetures', $calendar->getId());

        $this->client->request('GET', $url);
        $this->client->submitForm('Ajouter', [
            'time_off[startsAt]' => '2026-11-02T09:00',
            'time_off[endsAt]' => '2026-11-02T17:00',
            'time_off[reason]' => 'Formation',
        ]);
        self::assertResponseRedirects($url);

        // 9 h à Montréal (UTC−5 en novembre) = 14 h UTC
        $timeOff = self::getContainer()->get(TimeOffRepository::class)->findOneBy(['reason' => 'Formation']);
        self::assertSame('2026-11-02 14:00', $timeOff->getStartsAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.row-list', 'lun. 2 nov. 2026, 09:00');

        $this->client->submit($crawler->filter('.row-list form')->form());
        self::assertResponseRedirects($url);
        $this->entityManager()->clear();
        self::assertNull(self::getContainer()->get(TimeOffRepository::class)->findOneBy(['reason' => 'Formation']));
    }

    public function testTheIntegrationTabGivesTheCalendarCode(): void
    {
        $calendar = $this->createAgenda();
        $this->loginOwner($calendar->getCompany());

        $this->client->request('GET', sprintf('/espace/calendriers/%d/integration', $calendar->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#calendar-link', 'http://localhost/salon-lumiere/julie');
        self::assertSelectorTextContains('#embed-calendar', '<div data-kairos="http://localhost/integration/salon-lumiere/julie">');
        self::assertSelectorExists('button[data-copy="embed-calendar"]');
    }

    public function testACalendarWithBookingsIsClosedRatherThanDeleted(): void
    {
        $calendar = $this->createAgenda();
        $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00');
        $empty = $this->createCalendar($calendar->getCompany(), [], 'vide');
        $this->loginOwner($calendar->getCompany());

        $crawler = $this->client->request('GET', sprintf('/espace/calendriers/%d', $calendar->getId()));
        $this->client->submit($crawler->selectButton('Supprimer « Julie »')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'il ne peut pas être supprimé');

        $crawler = $this->client->request('GET', sprintf('/espace/calendriers/%d', $empty->getId()));
        $this->client->submit($crawler->selectButton('Supprimer « Vide »')->form());
        self::assertResponseRedirects('/espace/calendriers');

        $this->entityManager()->clear();
        $calendars = self::getContainer()->get(CalendarRepository::class);
        self::assertNotNull($calendars->find($calendar->getId()));
        self::assertNull($calendars->find($empty->getId()));
    }

    public function testAServiceWithBookingsCannotBeDeleted(): void
    {
        $calendar = $this->createAgenda();
        $service = $calendar->getServices()->first();
        $this->createBooking($calendar, $service, self::NEXT_MONDAY.' 10:00');
        $this->loginOwner($calendar->getCompany());

        $crawler = $this->client->request('GET', sprintf('/espace/prestations/%d', $service->getId()));
        $this->client->submit($crawler->selectButton('Supprimer « Séance individuelle »')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-warning', 'ne peut pas être supprimée');
        self::assertNotNull(self::getContainer()->get(ServiceRepository::class)->find($service->getId()));
    }

    /** Un membre ne voit et ne gère que les calendriers qui lui sont attribués, et pas le catalogue */
    public function testAMemberOnlyManagesTheirCalendars(): void
    {
        $julie = $this->createAgenda();
        $company = $julie->getCompany();
        $marc = $this->createCalendar($company, [], 'marc');
        $member = $this->createMember($company, CompanyRole::Member, 'marc@kairos.test');
        $member->addCalendar($marc);
        $this->entityManager()->flush();
        $this->client->loginUser($member);

        $this->client->request('GET', '/espace/calendriers');
        self::assertSelectorTextContains('.services', 'Marc');
        self::assertSelectorTextNotContains('.services', 'Julie');
        self::assertSelectorNotExists('a[href="/espace/calendriers/nouveau"]');
        self::assertSelectorNotExists('.space-nav a[href="/espace/prestations"]');

        $this->client->request('GET', sprintf('/espace/calendriers/%d/horaires', $marc->getId()));
        self::assertResponseIsSuccessful();

        foreach ([
            sprintf('/espace/calendriers/%d', $julie->getId()),
            '/espace/calendriers/nouveau',
            '/espace/prestations',
            sprintf('/espace/prestations/%d', $julie->getServices()->first()->getId()),
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testTheDashboardListsCalendarsUpcomingBookingsAndTheCompanyCode(): void
    {
        $calendar = $this->createAgenda();
        $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00', 'Inès Moreau');
        $this->loginOwner($calendar->getCompany());

        $this->client->request('GET', '/espace');

        self::assertSelectorTextContains('.space-cards', 'Julie');
        self::assertSelectorTextContains('.space-cards', 'Inès Moreau');
        self::assertSelectorTextContains('.space-cards', 'Lun. 26 oct. · 10:00');
        self::assertSelectorTextContains('#embed-company', 'http://localhost/integration/salon-lumiere');
    }

    private function loginOwner(Company $company, string $email = 'pro@kairos.test'): User
    {
        $owner = $this->createMember($company, CompanyRole::Owner, $email);
        $this->client->loginUser($owner);

        return $owner;
    }

    /** Envoie un formulaire comme le navigateur, avec des lignes ajoutées dans la page (collections) */
    private function postForm(string $url, string $name, array $data): void
    {
        $this->client->request('POST', $url, [$name => [...$data, '_token' => 'csrf-token']]);
    }

    private function reloadCalendar(Calendar $calendar): Calendar
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(CalendarRepository::class)->find($calendar->getId());
    }
}
