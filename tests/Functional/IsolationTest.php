<?php

namespace App\Tests\Functional;

use App\Entity\TimeOff;
use App\Enum\CompanyRole;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Chaque entreprise est isolée des autres : aucune page de l'espace ne s'ouvre sur l'objet d'une autre
 * entreprise, et l'espace super-admin reste fermé aux entreprises.
 *
 * Les routes de l'espace sont lues dans le routeur : une nouvelle route qui cible un objet est testée
 * d'office, et le test échoue tant qu'on ne lui a pas dit quel objet lui donner.
 */
final class IsolationTest extends WebTestCase
{
    use AgendaTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->freezeTime();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testNoSpacePageOpensOnAnotherCompanysObject(): void
    {
        // Salon Lumière et tout ce qu'une page de l'espace peut cibler
        $calendar = $this->createAgenda();
        $company = $calendar->getCompany();
        $service = $calendar->getServices()->first();
        $ids = [
            'calendar' => $calendar->getId(),
            'service' => $service->getId(),
            'booking' => $this->createBooking($calendar, $service, self::NEXT_MONDAY.' 10:00')->getId(),
            'member' => $this->createMember($company, CompanyRole::Member, 'marc@salon.test')->getId(),
            'timeOff' => $this->createTimeOff($calendar)->getId(),
        ];
        $this->createMember($company, email: 'julie@salon.test');

        // Connecté en propriétaire d'une autre entreprise
        $this->client->loginUser($this->createMember($this->createCompany('Cabinet Équilibre', 'cabinet-equilibre'), email: 'contact@equilibre.test'));

        $checked = 0;
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'app_space_') || [] === $route->compile()->getPathVariables()) {
                continue;
            }

            $subject = match (explode('/', $route->getPath())[2]) {
                'calendriers' => 'calendar',
                'prestations' => 'service',
                'rendez-vous' => 'booking',
                'equipe' => 'member',
                default => self::fail(sprintf('La route %s (%s) cible un objet inconnu de ce test : ajoutez-le.', $name, $route->getPath())),
            };
            $url = strtr($route->getPath(), ['{id}' => $ids[$subject], '{timeOff}' => $ids['timeOff']]);

            foreach ($route->getMethods() as $method) {
                $this->client->request($method, $url);
                self::assertResponseStatusCodeSame(403, sprintf('%s %s', $method, $url));
                ++$checked;
            }
        }

        self::assertGreaterThan(15, $checked);
    }

    public function testATimeOffCannotBeDeletedThroughAnotherCalendar(): void
    {
        $timeOff = $this->createTimeOff($this->createAgenda());
        $other = $this->createCompany('Cabinet Équilibre', 'cabinet-equilibre');
        $ownCalendar = $this->createCalendar($other, [], 'consultations');
        $this->client->loginUser($this->createMember($other, email: 'contact@equilibre.test'));

        // Son propre calendrier dans l'adresse, la fermeture d'un autre : introuvable
        $this->client->request('POST', sprintf('/espace/calendriers/%d/fermetures/%d/supprimer', $ownCalendar->getId(), $timeOff->getId()));

        self::assertResponseStatusCodeSame(404);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(TimeOff::class, $timeOff->getId()));
    }

    public function testListsOnlyShowTheCompanysOwnData(): void
    {
        $salon = $this->createAgenda();
        $this->createBooking($salon, $salon->getServices()->first(), self::NEXT_MONDAY.' 10:00', 'Inès Moreau');
        $this->createMember($salon->getCompany(), CompanyRole::Member, 'marc@salon.test');
        $other = $this->createCompany('Cabinet Équilibre', 'cabinet-equilibre');
        $this->createCalendar($other, [$this->createService($other, 'Appel découverte', 30)], 'consultations');
        $this->client->loginUser($this->createMember($other, email: 'contact@equilibre.test'));

        // Le compte connecté s'appelle aussi Julie : on regarde le contenu, pas le message d'accueil
        foreach ([
            '/espace' => ['.space-cards', ['Julie', 'Inès Moreau']],
            '/espace/rendez-vous' => ['main', ['Inès Moreau']],
            '/espace/calendriers' => ['main', ['Julie']],
            '/espace/prestations' => ['main', ['Séance individuelle']],
            '/espace/equipe' => ['main', ['marc@salon.test']],
        ] as $url => [$selector, $hidden]) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            foreach ($hidden as $text) {
                self::assertSelectorTextNotContains($selector, $text, $url);
            }
        }
    }

    public function testTheSuperAdminIsClosedToCompanies(): void
    {
        $this->client->loginUser($this->createMember($this->createCompany()));

        foreach (['/admin', '/admin/company', '/admin/user', '/admin/calendar', '/admin/booking', '/admin/service', '/admin/opening-hour', '/admin/time-off'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    private function createTimeOff(\App\Entity\Calendar $calendar): TimeOff
    {
        $timeOff = (new TimeOff())
            ->setCalendar($calendar)
            ->setStartsAt(self::paris('2026-11-02 00:00'))
            ->setEndsAt(self::paris('2026-11-03 00:00'))
            ->setReason('Congés');
        $this->entityManager()->persist($timeOff);
        $this->entityManager()->flush();

        return $timeOff;
    }
}
