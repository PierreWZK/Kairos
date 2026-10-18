<?php

namespace App\Tests\Functional;

use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La vitrine de Kairos, la démo d'intégration et les pages publiques aux couleurs de l'entreprise.
 */
final class ShowcaseTest extends WebTestCase
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

    public function testTheShowcaseListsTheDemoCompaniesThatExist(): void
    {
        $this->createAgenda();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre agenda en ligne');
        self::assertSelectorExists('#demos a[href="/salon-lumiere"]');
        self::assertSelectorNotExists('#demos a[href="/cabinet-equilibre"]');
        // Le code d'intégration d'exemple, affiché comme du texte
        self::assertSelectorTextContains('#integration .code', '<div data-kairos="http://localhost/integration/salon-lumiere">');
        self::assertSelectorTextContains('#integration .code', '<script src="http://localhost/embed.js" async></script>');
    }

    public function testTheIntegrationDemoEmbedsTheSalonCalendar(): void
    {
        $this->createAgenda();

        $this->client->request('GET', '/demo/integration');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.external-widget [data-kairos="http://localhost/integration/salon-lumiere"]');
        self::assertSelectorExists('script[src="/embed.js"]');
    }

    public function testTheIntegrationDemoNeedsTheDemoData(): void
    {
        $this->client->request('GET', '/demo/integration');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCompanyPagesUseTheCompanyColorAndName(): void
    {
        $calendar = $this->createAgenda();
        $calendar->getCompany()->setAccentColor('#b4513a');
        $this->entityManager()->flush();

        foreach (['/salon-lumiere', '/salon-lumiere/julie'] as $url) {
            $this->client->request('GET', $url);
            self::assertSelectorExists('body.branded[style="--brand: #b4513a"]', $url);
            self::assertSelectorTextContains('.company-header', 'Salon Lumière', $url);
            // Sans logo : l'initiale de l'entreprise
            self::assertSelectorTextContains('.company-header .monogram', 'S', $url);
        }

        $this->client->request('GET', '/salon-lumiere');
        self::assertSelectorTextContains('.calendar-card', 'Séance individuelle');
        self::assertSelectorTextContains('.calendar-card', 'à partir de 60 €');
    }
}
