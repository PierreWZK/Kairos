<?php

namespace App\Tests\Functional;

use App\Repository\BookingRepository;
use App\Repository\ServiceRepository;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'espace super-admin (EasyAdmin) : accès réservé, pages, annulation, suppression protégée.
 */
final class AdminTest extends WebTestCase
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

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/connexion');
    }

    public function testACompanyOwnerCannotEnterTheSuperAdmin(): void
    {
        $this->client->loginUser($this->createMember($this->createCompany()));

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('adminPages')]
    public function testEveryAdminPageLoads(string $url, string $title): void
    {
        $calendar = $this->createAgenda();
        $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00');
        $this->client->loginUser($this->createSuperAdmin());

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains($title);
    }

    /** @return iterable<array{string, string}> */
    public static function adminPages(): iterable
    {
        yield ['/admin', 'Tableau de bord'];
        yield ['/admin/company', 'Entreprises'];
        yield ['/admin/calendar', 'Calendriers'];
        yield ['/admin/booking', 'Réservations'];
        yield ['/admin/service', 'Prestations'];
        yield ['/admin/opening-hour', 'Horaires d\'ouverture'];
        yield ['/admin/time-off', 'Fermetures'];
        yield ['/admin/time-off/new', 'Fermeture'];
    }

    public function testBookingsCanNeitherBeCreatedNorDeleted(): void
    {
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00');
        $this->client->loginUser($this->createSuperAdmin());

        $this->client->request('GET', '/admin/booking/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/booking/%d/delete', $booking->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDashboardListsUpcomingBookingsOfAllCompanies(): void
    {
        $calendar = $this->createAgenda();
        $this->createBooking($calendar, $calendar->getServices()->first(), '2026-10-19 15:00', 'Inès Moreau');
        $other = $this->createCompany('Cabinet Équilibre', 'cabinet-equilibre');
        $consultations = $this->createCalendar($other, [$this->createService($other, 'Appel découverte', 30)], 'consultations');
        $this->createBooking($consultations, $consultations->getServices()->first(), self::NEXT_MONDAY.' 09:00', 'Nathan Petit');
        $this->client->loginUser($this->createSuperAdmin());

        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('#main', 'Inès Moreau');
        self::assertSelectorTextContains('#main', 'Salon Lumière · Julie');
        self::assertSelectorTextContains('#main', 'Nathan Petit');
        self::assertSelectorTextContains('#main', 'Cabinet Équilibre · Consultations');
    }

    public function testTheSuperAdminCancelsABookingAndTheCustomerIsWarned(): void
    {
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), '2026-10-19 09:00');
        $this->client->loginUser($this->createSuperAdmin());

        $crawler = $this->client->request('GET', sprintf('/admin/booking/%d', $booking->getId()));
        $cancelUrl = $crawler->filter(sprintf('form[action*="/admin/booking/%d/cancel?token="]', $booking->getId()))->attr('action');

        // Sans jeton : refusé
        $this->client->request('POST', sprintf('/admin/booking/%d/cancel', $booking->getId()));
        self::assertResponseStatusCodeSame(403);

        // Avec le jeton, et même à moins de 2 h du rendez-vous
        $this->client->request('POST', $cancelUrl);
        self::assertResponseRedirects(sprintf('/admin/booking/%d', $booking->getId()));

        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(0), 'annulé de notre côté');
        // Affiché dans le fuseau de l'entreprise, même si l'annulation part du super-admin
        self::assertEmailHtmlBodyContains(self::getMailerMessage(0), '09:00 → 10:00');

        $this->entityManager()->clear();
        self::assertTrue(self::getContainer()->get(BookingRepository::class)->find($booking->getId())->isCancelled());
    }

    public function testAServiceWithBookingsCannotBeDeleted(): void
    {
        $calendar = $this->createAgenda();
        $service = $calendar->getServices()->first();
        $this->createBooking($calendar, $service, self::NEXT_MONDAY.' 10:00');
        $this->client->loginUser($this->createSuperAdmin());

        $crawler = $this->client->request('GET', '/admin/service');
        $token = $crawler->filter('form input[name="token"]')->attr('value');
        $this->client->request('POST', sprintf('/admin/service/%d/delete', $service->getId()), ['token' => $token]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'ne peut pas être supprimée');
        $this->entityManager()->clear();
        self::assertNotNull(self::getContainer()->get(ServiceRepository::class)->find($service->getId()));
    }
}
