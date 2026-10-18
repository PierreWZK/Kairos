<?php

namespace App\Command;

use App\Demo\DemoData;
use App\Entity\Booking;
use App\Entity\Calendar;
use App\Entity\Company;
use App\Entity\User;
use App\Repository\CompanyRepository;
use App\Upload\LogoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remet la démo à zéro : supprime les entreprises de démonstration (Company::isDemo()) avec tout ce que les
 * visiteurs y ont changé, puis les recrée avec des rendez-vous à partir d'aujourd'hui.
 *
 * Les autres entreprises ne sont jamais touchées. En production, une tâche planifiée la lance chaque nuit.
 */
#[AsCommand(name: 'app:demo:reset', description: 'Recrée les entreprises de démonstration (les autres ne sont pas touchées)')]
final class ResetDemoCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyRepository $companies,
        private readonly LogoStorage $logos,
        private readonly DemoData $demo,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        // Une vraie entreprise ne se supprime jamais, même si elle a pris le lien d'une démo
        $taken = $this->companies->findBy(['slug' => DemoData::SLUGS, 'isDemo' => false]);
        if ([] !== $taken) {
            $io->error(sprintf(
                'Lien déjà pris par une entreprise qui n\'est pas une démo : %s. Rien n\'a été modifié.',
                implode(', ', array_map(static fn (Company $company): string => '/'.$company->getSlug(), $taken)),
            ));

            return Command::FAILURE;
        }

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em): void {
            $demos = $this->companies->findBy(['isDemo' => true]);
            foreach ($demos as $company) {
                $this->logos->remove($company);
            }

            // Les rendez-vous bloquent la suppression de leur calendrier (RESTRICT) : ils partent d'abord.
            // Le reste (calendriers, prestations, horaires, fermetures, comptes) suit l'entreprise par cascade.
            if ([] !== $demos) {
                $em->createQuery('DELETE FROM '.Booking::class.' b WHERE b.calendar IN (SELECT c.id FROM '.Calendar::class.' c WHERE c.company IN (:companies))')
                    ->setParameter('companies', $demos)
                    ->execute();
                $em->createQuery('DELETE FROM '.Company::class.' c WHERE c IN (:companies)')
                    ->setParameter('companies', $demos)
                    ->execute();
            }
            // Un compte de démo qui aurait quitté son entreprise
            $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:emails)')
                ->setParameter('emails', DemoData::EMAILS)
                ->execute();
            $em->clear();

            $this->demo->load($em);
        });

        $io->success(sprintf('Démo recréée : %s.', implode(', ', array_map(
            static fn (Company $company): string => '/'.$company->getSlug(),
            $this->companies->findBy(['isDemo' => true], ['name' => 'ASC']),
        ))));

        return Command::SUCCESS;
    }
}
