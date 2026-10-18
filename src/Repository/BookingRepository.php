<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Calendar;
use App\Entity\Service;
use App\Enum\BookingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /** @return list<Booking> Les rendez-vous du calendrier qui occupent un créneau (en attente ou confirmés) dans [from, to[ */
    public function findBlockingOverlapping(Calendar $calendar, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.calendar = :calendar')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('b.startsAt < :to')
            ->andWhere('b.endsAt > :from')
            ->setParameter('calendar', $calendar)
            ->setParameter('statuses', BookingStatus::blocking())
            ->setParameter('from', self::utc($from))
            ->setParameter('to', self::utc($to))
            ->orderBy('b.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Booking> Les prochains rendez-vous (en attente ou confirmés) de toute la plateforme, les plus proches d'abord */
    public function findUpcoming(\DateTimeImmutable $from, int $limit): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('s', 'c', 'co')
            ->join('b.service', 's')
            ->join('b.calendar', 'c')
            ->join('c.company', 'co')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('b.endsAt > :from')
            ->setParameter('statuses', BookingStatus::blocking())
            ->setParameter('from', self::utc($from))
            ->orderBy('b.startsAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countForService(Service $service): int
    {
        return $this->count(['service' => $service]);
    }

    /** Doctrine enregistre et compare l'heure telle quelle : les bornes doivent être en UTC, comme la base */
    private static function utc(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
