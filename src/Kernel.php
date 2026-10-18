<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        // Stockage et calculs en UTC, quel que soit le date.timezone du serveur. Chaque entreprise a son fuseau :
        // les horaires d'ouverture sont exprimés dedans, et l'affichage s'y fait (voir Twig\DisplayTimezone).
        date_default_timezone_set('UTC');
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
