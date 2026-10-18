<?php

namespace App\Repository;

use App\Entity\Calendar;
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

    /** @return array<int, list<OpeningHour>> Les plages d'ouverture du calendrier par jour ISO (1 = lundi), triées par heure */
    public function findAllByDay(Calendar $calendar): array
    {
        $hours = $this->createQueryBuilder('h')
            ->andWhere('h.calendar = :calendar')
            ->setParameter('calendar', $calendar)
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
