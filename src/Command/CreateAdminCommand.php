<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée le compte super-admin de la plateforme (accès à /admin), ou change son mot de passe s'il existe déjà.
 * Le mot de passe est demandé de façon masquée : il n'apparaît ni à l'écran ni dans l'historique du terminal.
 */
#[AsCommand(name: 'app:create-admin', description: 'Crée le compte super-admin (ou change son mot de passe)')]
final class CreateAdminCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Adresse e-mail du compte')] string $email): int
    {
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Adresse e-mail invalide.');

            return Command::INVALID;
        }

        $password = (string) $io->askHidden('Mot de passe (12 caractères minimum)', static function (?string $value): string {
            if (mb_strlen((string) $value) < 12) {
                throw new \RuntimeException('Le mot de passe doit faire au moins 12 caractères.');
            }

            return (string) $value;
        });

        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($email))]);
        $isNew = null === $user;
        $user ??= (new User())->setEmail($email)->setName('Super-admin');

        $user->setRoles(['ROLE_SUPER_ADMIN'])
            ->setIsVerified(true)
            ->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success($isNew ? "Compte créé : $email" : "Mot de passe mis à jour : $email");

        return Command::SUCCESS;
    }
}
