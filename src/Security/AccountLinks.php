<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les liens envoyés par e-mail pour vérifier une adresse et choisir un nouveau mot de passe.
 *
 * Rien n'est stocké en base : le lien est signé avec le secret de l'appli (UriSigner) et expire.
 * Il contient une empreinte de ce qu'il protège, ce qui l'invalide dès que cela change :
 * - vérification : l'adresse e-mail (un lien envoyé à une ancienne adresse ne vaut plus) ;
 * - mot de passe : le hachage du mot de passe actuel (le lien ne sert qu'une fois).
 */
final class AccountLinks
{
    public const string VERIFICATION_TTL = 'P2D';
    public const string RESET_TTL = 'PT1H';

    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function verificationUrl(User $user): string
    {
        return $this->signed('app_verify_email', $user, $user->getEmail(), self::VERIFICATION_TTL);
    }

    public function resetUrl(User $user): string
    {
        return $this->signed('app_reset_password', $user, $user->getPassword(), self::RESET_TTL);
    }

    public function isValidVerification(Request $request, User $user): bool
    {
        return $this->isValid($request, $user->getEmail());
    }

    public function isValidReset(Request $request, User $user): bool
    {
        return $this->isValid($request, $user->getPassword());
    }

    private function signed(string $route, User $user, string $protected, string $ttl): string
    {
        $url = $this->urlGenerator->generate($route, [
            'id' => $user->getId(),
            'empreinte' => self::fingerprint($protected),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateInterval($ttl));
    }

    private function isValid(Request $request, string $protected): bool
    {
        return $this->signer->checkRequest($request)
            && hash_equals(self::fingerprint($protected), $request->query->getString('empreinte'));
    }

    private static function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}
