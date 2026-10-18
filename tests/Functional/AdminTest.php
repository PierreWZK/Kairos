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
 * Le back-office : accès réservé, pages, annulation par le professionnel, suppression protégée.
 */
final class AdminTest extends WebTestCase
{
    use AgendaTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->freezeTime();
        $this->createWeekdayHours();
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

    public function testALoggedInUserWithoutTheAdminRoleIsForbidden(): void
    {
        $this->client->loginUser($this->createAdmin('ROLE_USER'));

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('adminPages')]
    public function testEveryAdminPageLoads(string $url, string $title): void
    {
        $this->createBooking($this->createService(), self::NEXT_MONDAY.' 10:00');
        $this->client->loginUser($this->createAdmin());

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains($title);
    }

    /** @return iterable<array{string, string}> */
    public static function adminPages(): iterable
    {
        yield ['/admin', 'Tableau de bord'];
        yield ['/admin/booking', 'Réservations'];
        yield ['/admin/service', 'Prestations'];
        yield ['/admin/service/new', 'Prestation'];
        yield ['/admin/opening-hour', 'Horaires d\'ouverture'];
        yield ['/admin/time-off', 'Fermetures'];
        yield ['/admin/time-off/new', 'Fermeture'];
    }

    public function testBookingsCanNeitherBeCreatedNorDeleted(): void
    {
        $booking = $this->createBooking($this->createService(), self::NEXT_MONDAY.' 10:00');
        $this->client->loginUser($this->createAdmin());

        $this->client->request('GET', '/admin/booking/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/booking/%d/delete', $booking->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDashboardListsUpcomingBookings(): void
    {
        $this->createBooking($this->createService(), '2026-10-19 15:00', 'Inès Moreau');
        $this->createBooking($this->createService('Appel découverte', 30), self::NEXT_MONDAY.' 09:00', 'Nathan Petit');
        $this->client->loginUser($this->createAdmin());

        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('#main', 'Inès Moreau');
        self::assertSelectorTextContains('#main', 'Nathan Petit');
    }

    public function testTheOwnerCancelsABookingAndTheCustomerIsWarned(): void
    {
        $booking = $this->createBooking($this->createService(), '2026-10-19 09:00');
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', sprintf('/admin/booking/%d', $booking->getId()));
        $cancelUrl = $crawler->filter(sprintf('form[action*="/admin/booking/%d/cancel?token="]', $booking->getId()))->attr('action');

        // Sans jeton : refusé
        $this->client->request('POST', sprintf('/admin/booking/%d/cancel', $booking->getId()));
        self::assertResponseStatusCodeSame(403);

        // Avec le jeton, et même à moins de 2 h du rendez-vous : le professionnel peut toujours annuler
        $this->client->request('POST', $cancelUrl);
        self::assertResponseRedirects(sprintf('/admin/booking/%d', $booking->getId()));

        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(0), 'annulé de notre côté');

        $this->entityManager()->clear();
        self::assertTrue(self::getContainer()->get(BookingRepository::class)->find($booking->getId())->isCancelled());
    }

    public function testAServiceWithBookingsCannotBeDeleted(): void
    {
        $service = $this->createService();
        $this->createBooking($service, self::NEXT_MONDAY.' 10:00');
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/service');
        $token = $crawler->filter('form input[name="token"]')->attr('value');
        $this->client->request('POST', sprintf('/admin/service/%d/delete', $service->getId()), ['token' => $token]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'ne peut pas être supprimée');
        $this->entityManager()->clear();
        self::assertNotNull(self::getContainer()->get(ServiceRepository::class)->find($service->getId()));
    }
}
