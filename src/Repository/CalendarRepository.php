<?php

namespace App\Repository;

use App\Entity\Calendar;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Calendar>
 */
class CalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Calendar::class);
    }

    /** Le calendrier ouvert à la réservation à l'adresse /{entreprise}/{slug} */
    public function findPublic(Company $company, string $slug): ?Calendar
    {
        return $this->findOneBy(['company' => $company, 'slug' => $slug, 'isActive' => true]);
    }
}
