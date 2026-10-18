<?php

namespace App\Repository;

use App\Entity\Calendar;
use App\Entity\TimeOff;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimeOff>
 */
class TimeOffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeOff::class);
    }

    /** @return list<TimeOff> Les fermetures du calendrier qui chevauchent la période [from, to[ */
    public function findOverlapping(Calendar $calendar, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.calendar = :calendar')
            ->andWhere('t.startsAt < :to')
            ->andWhere('t.endsAt > :from')
            ->setParameter('calendar', $calendar)
            ->setParameter('from', self::utc($from))
            ->setParameter('to', self::utc($to))
            ->orderBy('t.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Doctrine enregistre et compare l'heure telle quelle : les bornes doivent être en UTC, comme la base */
    private static function utc(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
