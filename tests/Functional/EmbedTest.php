<?php

namespace App\Tests\Functional;

use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le calendrier intégré dans le site d'une entreprise (/integration/…) : affichable dans une iframe de n'importe
 * quel site, parcours complet sans quitter l'intégration, et sans session (son cookie n'y serait pas envoyé).
 */
final class EmbedTest extends WebTestCase
{
    use AgendaTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le formulaire est posté depuis l'iframe : son origine est celle de Kairos, pas celle du site qui l'accueille
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->freezeTime();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testEmbeddedPagesCanBeFramedByAnySite(): void
    {
        $this->createAgenda();

        $this->client->request('GET', '/integration/salon-lumiere/julie');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Security-Policy', 'frame-ancestors *');
        self::assertResponseNotHasHeader('X-Frame-Options');
        // Ni en-tête ni pied de page de Kairos, thème clair imposé, pas d'indexation
        self::assertSelectorNotExists('.company-header');
        self::assertSelectorNotExists('.site-footer');
        self::assertSelectorExists('html[data-theme="light"]');
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');
        self::assertSelectorTextContains('.embed-credit', 'Kairos');
    }

    public function testOtherPagesRefuseToBeFramedByAnotherSite(): void
    {
        $this->createAgenda();

        foreach (['/', '/salon-lumiere/julie', '/connexion'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN', $url);
            self::assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'self'", $url);
        }
    }

    public function testACustomerBooksWithoutLeavingTheEmbeddedCalendar(): void
    {
        $calendar = $this->createAgenda();
        $this->createCalendar($calendar->getCompany(), $calendar->getServices()->toArray(), 'marc');
        $service = $calendar->getServices()->first();

        $crawler = $this->client->request('GET', '/integration/salon-lumiere');
        self::assertResponseIsSuccessful();
        $this->client->click($crawler->filter('a.calendar-card[href="/integration/salon-lumiere/julie"]')->link());

        $crawler = $this->client->click($this->client->getCrawler()->filter(sprintf('a.service-card[href="/integration/salon-lumiere/julie/%d"]', $service->getId()))->link());
        self::assertResponseIsSuccessful();
        $crawler = $this->client->click($crawler->filter(sprintf('a.planner-nav[aria-label="Semaine suivante"]'))->link());

        $this->client->click($crawler->filter(sprintf('a.slot-free[href^="/integration/"][href*="debut=%sT10:00"]', self::NEXT_MONDAY))->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.back-link[href^="/integration/salon-lumiere/julie/"]');

        $this->client->submitForm('Confirmer le rendez-vous', [
            'booking[customerName]' => 'Léa Dubois',
            'booking[customerEmail]' => 'lea@example.com',
        ]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/integration/rendez-vous/', $this->client->getResponse()->headers->get('Location'));
        self::assertEmailCount(1);
        // Le lien de l'e-mail mène à la page Kairos du rendez-vous, pas à la version intégrée
        self::assertEmailHtmlBodyContains(self::getMailerMessage(0), 'http://localhost/rendez-vous/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'À bientôt, Léa Dubois !');
        self::assertSelectorExists('a.button-ghost[href="/integration/salon-lumiere/julie"]');

        $this->assertNoSessionWasStarted();
    }

    public function testATakenSlotKeepsTheCustomerInTheEmbeddedCalendar(): void
    {
        $calendar = $this->createAgenda();
        $service = $calendar->getServices()->first();
        $this->createBooking($calendar, $service, self::NEXT_MONDAY.' 10:00');

        $this->client->request('GET', sprintf('/integration/salon-lumiere/julie/%d/creneau?debut=%sT10:00', $service->getId(), self::NEXT_MONDAY));

        self::assertResponseRedirects(sprintf('/integration/salon-lumiere/julie/%d?semaine=%s&indisponible=1', $service->getId(), self::NEXT_MONDAY));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'n\'est plus disponible');
        $this->assertNoSessionWasStarted();
    }

    public function testACustomerCancelsFromTheEmbeddedCalendar(): void
    {
        $calendar = $this->createAgenda();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), self::NEXT_MONDAY.' 10:00');

        $this->client->request('GET', '/integration/rendez-vous/'.$booking->getCancelToken());
        self::assertResponseIsSuccessful();
        // L'en-tête Origin du formulaire d'annulation doit rester envoyé par le navigateur
        self::assertSelectorExists('meta[name="referrer"][content="same-origin"]');

        $this->client->submitForm('Oui, annuler le rendez-vous');

        self::assertResponseRedirects('/integration/rendez-vous/'.$booking->getCancelToken());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Ce rendez-vous a été annulé');
        $this->assertNoSessionWasStarted();
    }

    public function testTheEmbeddedVersionFollowsTheSamePublicRules(): void
    {
        $calendar = $this->createAgenda();
        $calendar->getCompany()->setIsSuspended(true);
        $this->entityManager()->flush();

        $this->client->request('GET', '/integration/salon-lumiere');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/integration/inconnue/julie');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLoaderScriptIsServed(): void
    {
        $script = file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/public/embed.js');

        self::assertStringContainsString('[data-kairos]', $script);
        // Les messages ne sont acceptés que de l'iframe elle-même, venant de Kairos
        self::assertStringContainsString('event.origin !== url.origin', $script);
    }

    private function assertNoSessionWasStarted(): void
    {
        $cookies = $this->client->getCookieJar()->all();
        self::assertSame([], array_map(static fn ($cookie) => $cookie->getName(), $cookies), 'Le parcours intégré ne doit pas créer de session.');
    }
}
