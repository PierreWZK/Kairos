<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Notification\BookingMailer;
use App\Repository\BookingRepository;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La démo publique : app:demo:reset recrée les entreprises de démonstration sans toucher aux autres,
 * et leurs comptes publics ne peuvent ni bloquer les visiteurs suivants ni envoyer d'e-mails.
 */
final class DemoTest extends WebTestCase
{
    use AgendaTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheResetRecreatesOnlyTheDemoCompanies(): void
    {
        // Une vraie entreprise, avec un rendez-vous
        $real = $this->createCompany('Atelier Éclat', 'atelier-eclat');
        $calendar = $this->createCalendar($real, [$this->createService($real)], 'nina');
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), '2030-01-07 10:00');

        $this->resetDemo();
        $salon = $this->companies()->findOneBy(['slug' => 'salon-lumiere']);
        self::assertTrue($salon->isDemo());
        self::assertTrue($this->companies()->findOneBy(['slug' => 'cabinet-equilibre'])->isDemo());
        self::assertNotNull($this->user('julie@salon-lumiere.test'));
        self::assertNull($this->user('admin@kairos.test'), 'Pas de super-admin de démo en production');

        // Un visiteur modifie la démo ; la nuit suivante, tout revient
        $salon->setName('Salon piraté');
        $this->entityManager()->remove($this->user('marc@salon-lumiere.test'));
        $this->entityManager()->flush();

        $this->resetDemo();
        self::assertSame('Salon Lumière', $this->companies()->findOneBy(['slug' => 'salon-lumiere'])->getName());
        self::assertNotNull($this->user('marc@salon-lumiere.test'));
        self::assertCount(2, $this->companies()->findBy(['isDemo' => true]));

        // La vraie entreprise et son rendez-vous n'ont pas bougé
        self::assertFalse($this->companies()->findOneBy(['slug' => 'atelier-eclat'])->isDemo());
        self::assertNotNull(self::getContainer()->get(BookingRepository::class)->find($booking->getId()));
    }

    public function testARealCompanyUsingADemoLinkIsNeverDeleted(): void
    {
        $real = $this->createCompany();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:demo:reset'));
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('/salon-lumiere', $tester->getDisplay());
        $this->entityManager()->clear();
        self::assertNotNull($this->companies()->find($real->getId()));
        self::assertNull($this->companies()->findOneBy(['slug' => 'cabinet-equilibre']), 'Rien n\'a été créé');
    }

    public function testADemoAccountCannotLockOutTheNextVisitors(): void
    {
        $this->resetDemo();
        $this->client->loginUser($this->user('julie@salon-lumiere.test'));

        // Adresse : champ désactivé, et ignorée même si la requête la contient
        $crawler = $this->client->request('GET', '/espace/compte');
        self::assertSelectorExists('input[name="profile[email]"][disabled]');
        self::assertSelectorNotExists('form[name="change_password"]');
        $values = $crawler->selectButton('Enregistrer le profil')->form()->getPhpValues();
        $values['profile']['email'] = 'pirate@example.com';
        $this->client->request('POST', '/espace/compte', $values);
        self::assertResponseRedirects('/espace/compte');
        self::assertNotNull($this->user('julie@salon-lumiere.test'));

        // Mot de passe : refusé
        $this->client->request('POST', '/espace/compte', ['change_password' => [
            'currentPassword' => 'kairos-demo',
            'plainPassword' => ['first' => 'Nouvelle-Aube-2027', 'second' => 'Nouvelle-Aube-2027'],
        ]]);
        self::assertResponseStatusCodeSame(403);

        // Lien de la page : figé
        $this->client->request('GET', '/espace/entreprise');
        self::assertSelectorExists('input[name="company[slug]"][disabled]');

        // Invitations : coupées
        $this->client->request('GET', '/espace/equipe');
        self::assertSelectorNotExists('a[href="/espace/equipe/inviter"]');
        $this->client->request('GET', '/espace/equipe/inviter');
        self::assertResponseRedirects('/espace/equipe');
        self::assertEmailCount(0);
    }

    public function testNoEmailIsSentToTheDemoAccounts(): void
    {
        $this->resetDemo();
        $salon = $this->companies()->findOneBy(['slug' => 'salon-lumiere']);
        $calendar = $salon->getCalendars()->first();
        $booking = $this->createBooking($calendar, $calendar->getServices()->first(), '2030-01-08 10:00');

        // Le client reçoit sa confirmation ; l'équipe fictive, rien
        self::getContainer()->get(BookingMailer::class)->sendConfirmation($booking);
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(0), 'To', 'camille@example.com');

        // Mot de passe oublié : la page répond comme d'habitude, sans e-mail
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['form[email]' => 'julie@salon-lumiere.test']);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    private function resetDemo(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:demo:reset'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->entityManager()->clear();
    }

    private function companies(): CompanyRepository
    {
        return self::getContainer()->get(CompanyRepository::class);
    }

    private function user(string $email): ?User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }
}
