<?php

namespace App\Twig;

use App\Entity\Company;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/**
 * Fuseau dans lequel Twig affiche les dates (|date, |format_date…).
 *
 * Les dates sont en UTC : une page ou un e-mail d'une entreprise doit les montrer dans le fuseau de cette entreprise.
 */
final class DisplayTimezone
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function useCompany(Company $company): void
    {
        $this->set($company->getTimezone());
    }

    /**
     * Exécute $callback dans le fuseau de l'entreprise, puis revient au fuseau précédent
     * (pour un e-mail envoyé depuis une page d'un autre fuseau, comme le super-admin).
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withCompany(Company $company, callable $callback): mixed
    {
        $core = $this->twig->getExtension(CoreExtension::class);
        $previous = $core->getTimezone();
        $core->setTimezone($company->getTimezone());

        try {
            return $callback();
        } finally {
            $core->setTimezone($previous);
        }
    }

    private function set(string $timezone): void
    {
        $this->twig->getExtension(CoreExtension::class)->setTimezone($timezone);
    }
}
