<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\CompanyRole;
use App\Repository\UserRepository;
use App\Tests\AgendaTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'équipe d'une entreprise : invitation, acceptation, calendriers attribués, retrait.
 */
final class SpaceTeamTest extends WebTestCase
{
    use AgendaTestTrait;

    private const string PASSWORD = 'Horloge-Ambrée-42';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->freezeTime();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnInvitedMemberChoosesAPasswordAndManagesTheirCalendar(): void
    {
        $julie = $this->createAgenda();
        $company = $julie->getCompany();
        $marc = $this->createCalendar($company, [], 'marc');
        $this->client->loginUser($this->createMember($company));

        $crawler = $this->client->request('GET', '/espace/equipe/inviter');
        $form = $crawler->selectButton('Envoyer l\'invitation')->form([
            'member[name]' => 'Marc Lefèvre',
            'member[email]' => 'Marc@Salon.test',
            'member[companyRole]' => 'member',
        ]);
        $this->client->submit($this->tickCalendars($form, $marc->getId()));

        self::assertResponseRedirects('/espace/equipe');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'marc@salon.test');
        self::assertEmailHeaderSame($email, 'Subject', 'Julie Moreau vous invite sur Kairos');
        $link = $this->linkFromEmail();

        $this->client->followRedirect();
        self::assertSelectorTextContains('.team-list', 'Invitation envoyée');

        // Sans mot de passe, pas de connexion
        $invited = $this->findUser('marc@salon.test');
        self::assertTrue($invited->isInvitationPending());
        self::assertFalse($invited->isVerified());

        // L'invité ouvre le lien, déconnecté
        $this->client->request('GET', '/deconnexion');
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rejoignez Salon Lumière');
        $this->client->submitForm('Rejoindre l\'équipe', [
            'invitation[name]' => 'Marc L.',
            'invitation[plainPassword][first]' => self::PASSWORD,
            'invitation[plainPassword][second]' => self::PASSWORD,
        ]);

        self::assertResponseRedirects('/espace');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bonjour Marc L.');
        self::assertSelectorTextContains('.flash-success', 'Bienvenue dans l\'équipe de Salon Lumière');

        $member = $this->findUser('marc@salon.test');
        self::assertTrue($member->isVerified());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($member, self::PASSWORD));

        // Son calendrier, pas celui de Julie, ni la page Équipe
        $this->client->request('GET', sprintf('/espace/calendriers/%d', $marc->getId()));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', sprintf('/espace/calendriers/%d', $julie->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/espace/equipe');
        self::assertResponseStatusCodeSame(403);

        // Le lien ne sert qu'une fois
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAPendingInvitationCannotLogIn(): void
    {
        $this->createPendingMember($this->createCompany());

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['_username' => 'pending@salon.test', '_password' => 'nimporte-quoi']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-danger', 'lien de votre invitation');
    }

    public function testATamperedOrForeignLinkIsRefused(): void
    {
        $company = $this->createCompany();
        $this->client->loginUser($this->createMember($company));
        $this->client->request('GET', '/espace/equipe/inviter');
        $this->client->submitForm('Envoyer l\'invitation', ['member[name]' => 'Marc', 'member[email]' => 'marc@salon.test']);
        $link = $this->linkFromEmail();

        $this->client->request('GET', '/deconnexion');
        $this->client->request('GET', str_replace('empreinte=', 'empreinte=0', $link));
        self::assertResponseStatusCodeSame(400);

        // Un lien valable pour un autre compte ne s'applique pas à celui-ci
        $other = $this->findUser('pro@kairos.test');
        $this->client->request('GET', preg_replace('#/invitation/\d+#', '/invitation/'.$other->getId(), $link));
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnAddressAlreadyUsedIsRefused(): void
    {
        $this->createMember($this->createCompany('Cabinet Équilibre', 'cabinet-equilibre'), email: 'nina@example.com');
        $this->client->loginUser($this->createMember($this->createCompany()));

        $this->client->request('GET', '/espace/equipe/inviter');
        $this->client->submitForm('Envoyer l\'invitation', ['member[name]' => 'Nina', 'member[email]' => 'nina@example.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Un compte existe déjà avec cette adresse.');
        self::assertEmailCount(0);
    }

    public function testTheOwnerChangesCalendarsAndRemovesAMember(): void
    {
        $julie = $this->createAgenda();
        $company = $julie->getCompany();
        $marcCalendar = $this->createCalendar($company, [], 'marc');
        $owner = $this->createMember($company);
        $marc = $this->createMember($company, CompanyRole::Member, 'marc@salon.test');
        $marc->addCalendar($marcCalendar);
        $this->entityManager()->flush();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', sprintf('/espace/equipe/%d', $marc->getId()));
        $this->client->submit($this->tickCalendars($crawler->selectButton('Enregistrer')->form(), $julie->getId()));
        self::assertResponseRedirects('/espace/equipe');
        self::assertSame([$julie->getId()], $this->findUser('marc@salon.test')->getCalendars()->map(fn ($c) => $c->getId())->getValues());

        // Devenu propriétaire, il gère tout : plus d'attribution
        $this->client->request('GET', sprintf('/espace/equipe/%d', $marc->getId()));
        $this->client->submitForm('Enregistrer', ['member[companyRole]' => 'owner']);
        $promoted = $this->findUser('marc@salon.test');
        self::assertTrue($promoted->isOwner());
        self::assertCount(0, $promoted->getCalendars());

        $this->client->request('GET', sprintf('/espace/equipe/%d', $marc->getId()));
        $this->client->submitForm(sprintf('Retirer %s', 'Julie Moreau'));
        self::assertResponseRedirects('/espace/equipe');
        self::assertNull($this->findUser('marc@salon.test'));
    }

    public function testTheTeamIsOnlyManagedByAnOwnerOfTheSameCompany(): void
    {
        $company = $this->createCompany();
        $owner = $this->createMember($company);
        $member = $this->createMember($company, CompanyRole::Member, 'marc@salon.test');
        $other = $this->createMember($this->createCompany('Cabinet Équilibre', 'cabinet-equilibre'), email: 'nina@example.com');
        $this->client->loginUser($owner);

        // Ni son propre compte (l'entreprise garde un propriétaire), ni celui d'une autre entreprise
        $this->client->request('GET', sprintf('/espace/equipe/%d', $owner->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', sprintf('/espace/equipe/%d', $other->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', sprintf('/espace/equipe/%d/retirer', $owner->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($member);
        $this->client->request('GET', '/espace/equipe');
        self::assertResponseStatusCodeSame(403);
    }

    public function testBookingNotificationsOnlyReachTheCalendarTeam(): void
    {
        $julie = $this->createAgenda();
        $company = $julie->getCompany();
        $service = $company->getServices()->first();
        $marcCalendar = $this->createCalendar($company, [$service], 'marc');
        $this->createMember($company, email: 'owner@salon.test');
        $this->createMember($company, CompanyRole::Member, 'julie@salon.test')->addCalendar($julie);
        $this->createMember($company, CompanyRole::Member, 'marc@salon.test')->addCalendar($marcCalendar);
        $this->createPendingMember($company)->addCalendar($julie);
        $this->entityManager()->flush();

        $booking = $this->createBooking($julie, $service, self::NEXT_MONDAY.' 10:00');
        self::getContainer()->get(\App\Notification\BookingMailer::class)->sendConfirmation($booking);

        self::assertEmailCount(2);
        $companyEmail = self::getMailerMessage(1);
        $to = array_map(static fn ($address): string => $address->getAddress(), $companyEmail->getTo());
        sort($to);
        self::assertSame(['julie@salon.test', 'owner@salon.test'], $to);
    }

    private function createPendingMember(\App\Entity\Company $company): User
    {
        $user = (new User())
            ->setEmail('pending@salon.test')
            ->setName('Invité')
            ->setCompany($company)
            ->setCompanyRole(CompanyRole::Member)
            ->setPassword('');
        $company->getUsers()->add($user);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    /** Coche exactement les calendriers $ids */
    private function tickCalendars(Form $form, int ...$ids): Form
    {
        foreach ($form['member[calendars]'] as $checkbox) {
            \in_array((int) $checkbox->availableOptionValues()[0], $ids, true) ? $checkbox->tick() : $checkbox->untick();
        }

        return $form;
    }

    private function linkFromEmail(int $index = 0): string
    {
        $html = self::getMailerMessage($index)->getHtmlBody();
        self::assertMatchesRegularExpression('#href="(http://localhost/invitation/[^"]+)"#', $html);
        preg_match('#href="(http://localhost/invitation/[^"]+)"#', $html, $match);

        return html_entity_decode($match[1]);
    }

    private function findUser(string $email): ?User
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }
}
