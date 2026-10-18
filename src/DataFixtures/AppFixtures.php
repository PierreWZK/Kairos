<?php

namespace App\DataFixtures;

use App\Demo\DemoData;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Données de dev : les entreprises de démo (DemoData) et un compte super-admin.
 * Tous les comptes ont le mot de passe DemoData::DEMO_PASSWORD.
 *
 * Les fixtures vident toute la base : en production, c'est app:demo:reset qui recrée la démo, sans super-admin.
 */
class AppFixtures extends Fixture
{
    public const string SUPER_ADMIN_EMAIL = 'admin@kairos.test';

    public function __construct(
        private readonly DemoData $demo,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $manager->persist($this->demo->user(self::SUPER_ADMIN_EMAIL, 'Super-admin')->setRoles(['ROLE_SUPER_ADMIN']));
        $this->demo->load($manager);
    }
}
