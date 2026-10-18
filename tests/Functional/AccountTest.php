<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\CompanyRole;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les comptes d'entreprise : inscription, vérification de l'adresse, connexion, mot de passe oublié.
 */
final class AccountTest extends WebTestCase
{
    use AgendaTestTrait;

    private const string PASSWORD = 'Horloge-Ambrée-42';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le CSRF « stateless » de Symfony vérifie l'origine de la requête, comme le ferait un navigateur
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->freezeTime();
        // Les compteurs des limiteurs (renvoi de lien, mot de passe oublié) repartent de zéro
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testACompanySignsUpAndLandsInItsSpace(): void
    {
        $this->register(['registration[companyName]' => 'Atelier Éclat', 'registration[companySlug]' => '']);

        self::assertResponseRedirects('/espace');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'nina@example.com');
        self::assertEmailHeaderSame($email, 'Subject', 'Confirmez votre adresse e-mail');

        // Lien laissé vide : déduit du nom
        $company = self::getContainer()->get(CompanyRepository::class)->findOneBy(['slug' => 'atelier-eclat']);
        self::assertNotNull($company);
        self::assertSame('Europe/Paris', $company->getTimezone());

        $user = $this->findUser('nina@example.com');
        self::assertSame($company->getId(), $user->getCompany()->getId());
        self::assertSame(CompanyRole::Owner, $user->getCompanyRole());
        self::assertFalse($user->isVerified());
        self::assertNotSame(self::PASSWORD, $user->getPassword());

        // Connecté d'office, avec un rappel de vérification
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bonjour Nina Roux');
        self::assertSelectorTextContains('.verify-box', 'nina@example.com');

        $this->client->request('GET', '/atelier-eclat');
        self::assertResponseIsSuccessful();
    }

    public function testInvalidSignupsAreExplainedInFrench(): void
    {
        $this->createMember($this->createCompany(), email: 'nina@example.com');

        $this->register(['registration[companySlug]' => 'salon-lumiere', 'registration[plainPassword]' => 'azerty']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Ce lien est déjà utilisé par une autre entreprise.');
        self::assertSelectorTextContains('.booking-form', 'Un compte existe déjà avec cette adresse.');
        self::assertSelectorTextContains('.booking-form', 'Au moins 8 caractères.');

        $this->register(['registration[companySlug]' => 'admin', 'registration[email]' => 'autre@example.com', 'registration[plainPassword]' => 'motdepasse']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Ce lien est réservé par Kairos');
        self::assertSelectorTextContains('.booking-form', 'trop facile à deviner');

        self::assertEmailCount(0);
    }

    public function testTheVerificationLinkConfirmsTheAddressEvenWhenLoggedOut(): void
    {
        $this->register();
        $link = $this->linkFromEmail(0);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);

        self::assertResponseRedirects('/connexion');
        self::assertTrue($this->findUser('nina@example.com')->isVerified());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'adresse e-mail est confirmée');
    }

    public function testAnExpiredOrTamperedVerificationLinkIsRefused(): void
    {
        $this->register();
        $link = $this->linkFromEmail(0);

        $this->client->request('GET', str_replace('empreinte=', 'empreinte=0', $link));
        self::assertResponseStatusCodeSame(400);

        $this->freezeTime('2026-10-21 08:01'); // 48 h et une minute plus tard
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('h1', 'Ce lien ne fonctionne plus');

        self::assertFalse($this->findUser('nina@example.com')->isVerified());
    }

    public function testTheVerificationLinkIsResentAFewTimesAtMost(): void
    {
        $this->register();
        $crawler = $this->client->followRedirect();
        $token = $crawler->filter('.verify-box input[name="_token"]')->attr('value');

        for ($i = 1; $i <= 3; ++$i) {
            $this->client->request('POST', '/verification/email/renvoyer', ['_token' => $token]);
            self::assertResponseRedirects('/espace');
            self::assertEmailCount(1);
        }

        // Le quatrième renvoi du quart d'heure est refusé
        $this->client->request('POST', '/verification/email/renvoyer', ['_token' => $token]);
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'patientez quelques minutes');
    }

    public function testAnOwnerLogsInToTheirSpace(): void
    {
        $this->createUserWithPassword();

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['_username' => 'julie@salon.test', '_password' => self::PASSWORD]);

        self::assertResponseRedirects('http://localhost/espace');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bonjour Julie Moreau');
        self::assertSelectorNotExists('.verify-box');

        // Déjà connecté : la page de connexion renvoie à l'espace
        $this->client->request('GET', '/connexion');
        self::assertResponseRedirects('/espace');
    }

    public function testTheSuperAdminIsSentToEasyAdmin(): void
    {
        $this->client->loginUser($this->createSuperAdmin());

        $this->client->request('GET', '/espace');

        self::assertResponseRedirects('/admin');
    }

    public function testWrongCredentialsAndSuspendedCompaniesCannotLogIn(): void
    {
        $user = $this->createUserWithPassword();

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['_username' => 'julie@salon.test', '_password' => 'mauvais']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-danger', 'Identifiants invalides');

        // Le kernel a redémarré depuis la création : on passe par l'EntityManager actuel
        self::getContainer()->get(CompanyRepository::class)->find($user->getCompany()->getId())->setIsSuspended(true);
        $this->entityManager()->flush();
        $this->client->submitForm('Se connecter', ['_username' => 'julie@salon.test', '_password' => self::PASSWORD]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-danger', 'Ce compte est suspendu');
    }

    public function testTheCompanySpaceRequiresALogin(): void
    {
        $this->client->request('GET', '/espace');

        self::assertResponseRedirects('/connexion');
    }

    public function testAForgottenPasswordIsReplacedOnceWithTheEmailedLink(): void
    {
        $this->createUserWithPassword(verified: false);

        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['form[email]' => 'Julie@Salon.test']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vérifiez vos e-mails');
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'Subject', 'Choisissez un nouveau mot de passe');
        $link = $this->linkFromEmail(0);

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="referrer"][content="same-origin"]');
        $this->client->submitForm('Enregistrer', [
            'new_password[plainPassword][first]' => 'Nouvelle-Aube-2027',
            'new_password[plainPassword][second]' => 'Nouvelle-Aube-2027',
        ]);
        self::assertResponseRedirects('/connexion');

        // Le lien a servi : le mot de passe a changé, son empreinte aussi
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['_username' => 'julie@salon.test', '_password' => 'Nouvelle-Aube-2027']);
        self::assertResponseRedirects('http://localhost/espace');
        // Recevoir le lien prouve l'adresse
        self::assertTrue($this->findUser('julie@salon.test')->isVerified());
    }

    public function testAForgottenPasswordPageRevealsNothingAboutUnknownAddresses(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['form[email]' => 'inconnu@example.com']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vérifiez vos e-mails');
        self::assertEmailCount(0);
    }

    public function testAResetLinkExpiresAfterAnHour(): void
    {
        $this->createUserWithPassword();
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['form[email]' => 'julie@salon.test']);
        $link = $this->linkFromEmail(0);

        $this->freezeTime('2026-10-19 09:01');
        $this->client->request('GET', $link);

        self::assertResponseStatusCodeSame(400);
    }

    public function testPasswordResetRequestsAreLimited(): void
    {
        $this->createUserWithPassword();

        for ($i = 1; $i <= 4; ++$i) {
            $this->client->request('GET', '/mot-de-passe-oublie');
            $this->client->submitForm('Recevoir le lien', ['form[email]' => 'julie@salon.test']);
        }

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('.flash-warning', 'Trop de demandes');
        self::assertEmailCount(0); // ce dernier envoi seulement : les 3 premiers sont partis
    }

    /** @param array<string, string> $overrides */
    private function register(array $overrides = []): void
    {
        $this->client->request('GET', '/inscription');
        $this->client->submitForm('Créer mon espace', [
            'registration[companyName]' => 'Atelier Éclat',
            'registration[companySlug]' => 'atelier-eclat',
            'registration[name]' => 'Nina Roux',
            'registration[email]' => 'nina@example.com',
            'registration[plainPassword]' => self::PASSWORD,
            ...$overrides,
        ]);
    }

    /** Le lien du bouton de l'e-mail n° $index */
    private function linkFromEmail(int $index): string
    {
        $html = self::getMailerMessage($index)->getHtmlBody();
        self::assertMatchesRegularExpression('#href="(http://localhost/(?:verification|mot-de-passe-oublie)/[^"]+)"#', $html);
        preg_match('#href="(http://localhost/(?:verification|mot-de-passe-oublie)/[^"]+)"#', $html, $match);

        return html_entity_decode($match[1]);
    }

    private function createUserWithPassword(bool $verified = true): User
    {
        $user = (new User())
            ->setEmail('julie@salon.test')
            ->setName('Julie Moreau')
            ->setCompany($this->createCompany())
            ->setCompanyRole(CompanyRole::Owner)
            ->setIsVerified($verified);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function findUser(string $email): User
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }
}
