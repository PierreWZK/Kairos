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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * La fiche de l'entreprise (logo compris) et la page « Mon compte ».
 */
final class SpaceSettingsTest extends WebTestCase
{
    use AgendaTestTrait;

    private const string PASSWORD = 'Horloge-Ambrée-42';

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

    public function testTheOwnerUpdatesThePublicPage(): void
    {
        $this->client->loginUser($this->createMember($this->createCompany()));

        $this->client->request('GET', '/espace/entreprise');
        $this->client->submitForm('Enregistrer', [
            'company[name]' => 'Salon Lumière & Co',
            'company[description]' => 'Coiffure à la Croix-Rousse.',
            'company[accentColor]' => '#3d4f8a',
            'company[logo]' => $this->upload('logo.png', $this->png()),
        ]);

        self::assertResponseRedirects('/espace/entreprise');
        $company = $this->company();
        self::assertSame('Salon Lumière & Co', $company->getName());
        self::assertSame('#3d4f8a', $company->getAccentColor());
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}\.png$/', $company->getLogoFilename());
        self::assertFileExists($this->logoDir().'/'.$company->getLogoFilename());

        $this->client->request('GET', '/salon-lumiere');
        self::assertSelectorExists(sprintf('.company-header img[src="/uploads/logos/%s"]', $company->getLogoFilename()));
        self::assertSelectorExists('body[style="--brand: #3d4f8a"]');
    }

    public function testAnSvgLogoIsCleanedAndAFakeImageIsRefused(): void
    {
        $this->client->loginUser($this->createMember($this->createCompany()));

        $this->client->request('GET', '/espace/entreprise');
        $this->client->submitForm('Enregistrer', [
            'company[logo]' => $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><circle cx="5" cy="5" r="4" fill="red"/></svg>'),
        ]);
        self::assertResponseRedirects('/espace/entreprise');
        $svg = file_get_contents($this->logoDir().'/'.$this->company()->getLogoFilename());
        self::assertStringContainsString('<circle', $svg);
        self::assertStringNotContainsString('script', $svg);
        self::assertStringNotContainsString('onload', $svg);

        // Un fichier texte déguisé en PNG
        $this->client->request('GET', '/espace/entreprise');
        $this->client->submitForm('Enregistrer', ['company[logo]' => $this->upload('logo.png', 'pas une image')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Formats acceptés : PNG, JPEG, WebP ou SVG.');
    }

    public function testInvalidChangesAreRefusedWithoutTouchingTheCompany(): void
    {
        $this->createCompany('Cabinet Équilibre', 'cabinet-equilibre');
        $this->client->loginUser($this->createMember($this->createCompany()));

        $this->client->request('GET', '/espace/entreprise');
        $this->client->submitForm('Enregistrer', [
            'company[slug]' => 'cabinet-equilibre',
            'company[accentColor]' => '#f5c518',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Ce lien est déjà utilisé');
        self::assertSelectorTextContains('.booking-form', 'Cette couleur est trop claire');
        // La barre de l'espace garde le lien enregistré
        self::assertSelectorExists('.space-nav a[href="/salon-lumiere"]');
        self::assertSame('salon-lumiere', $this->company()->getSlug());
    }

    public function testAMemberCannotEditTheCompany(): void
    {
        $this->client->loginUser($this->createMember($this->createCompany(), CompanyRole::Member, 'marc@kairos.test'));

        $this->client->request('GET', '/espace/entreprise');

        self::assertResponseStatusCodeSame(403);
    }

    public function testChangingEmailNeedsThePasswordAndANewVerification(): void
    {
        $this->client->loginUser($this->createOwnerWithPassword());

        $this->client->request('GET', '/espace/compte');
        $this->client->submitForm('Enregistrer le profil', ['profile[email]' => 'julie@nouveau.test']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.booking-form', 'Indiquez votre mot de passe actuel');
        self::assertNotNull($this->findUser('julie@salon.test'));

        $this->client->submitForm('Enregistrer le profil', [
            'profile[email]' => 'julie@nouveau.test',
            'profile[currentPassword]' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/espace/compte');
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(0), 'To', 'julie@nouveau.test');

        // Toujours connecté, avec la nouvelle adresse à confirmer
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-success', 'confirmez-la');
        self::assertFalse($this->findUser('julie@nouveau.test')->isVerified());
    }

    public function testChangingThePasswordKeepsTheSessionOpen(): void
    {
        $this->client->loginUser($this->createOwnerWithPassword());

        $this->client->request('GET', '/espace/compte');
        $this->client->submitForm('Changer le mot de passe', [
            'change_password[currentPassword]' => 'mauvais',
            'change_password[plainPassword][first]' => 'Nouvelle-Aube-2027',
            'change_password[plainPassword][second]' => 'Nouvelle-Aube-2027',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Ce n\'est pas votre mot de passe actuel');

        $this->client->submitForm('Changer le mot de passe', [
            'change_password[currentPassword]' => self::PASSWORD,
            'change_password[plainPassword][first]' => 'Nouvelle-Aube-2027',
            'change_password[plainPassword][second]' => 'Nouvelle-Aube-2027',
        ]);
        self::assertResponseRedirects('/espace/compte');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $user = $this->findUser('julie@salon.test');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'Nouvelle-Aube-2027'));
    }

    public function testAnUnverifiedUserCanStillFixTheirAddress(): void
    {
        $user = $this->createOwnerWithPassword();
        $user->setIsVerified(false);
        $this->entityManager()->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/espace/compte');

        self::assertResponseIsSuccessful();
    }

    private function company(): \App\Entity\Company
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(CompanyRepository::class)->findOneBy(['slug' => 'salon-lumiere']);
    }

    private function findUser(string $email): ?User
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    private function createOwnerWithPassword(): User
    {
        $user = (new User())
            ->setEmail('julie@salon.test')
            ->setName('Julie Moreau')
            ->setCompany($this->createCompany())
            ->setCompanyRole(CompanyRole::Owner)
            ->setIsVerified(true);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** Un vrai PNG de 1 × 1 pixel */
    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    private function logoDir(): string
    {
        return self::getContainer()->getParameter('app.logo_dir');
    }
}
