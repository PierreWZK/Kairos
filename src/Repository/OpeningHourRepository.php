<?php

namespace App\Repository;

use App\Entity\OpeningHour;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpeningHour>
 */
class OpeningHourRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpeningHour::class);
    }
    /** @return array<int, list<OpeningHour>> Les plages d'ouverture par jour ISO (1 = lundi), triées par heure */
    public function findAllByDay(): array
    {
        $hours = $this->createQueryBuilder('h')
            ->orderBy('h.dayOfWeek', 'ASC')
            ->addOrderBy('h.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        $byDay = [];
        foreach ($hours as $hour) {
            $byDay[$hour->getDayOfWeek()][] = $hour;
        }

        return $byDay;
    }
}
