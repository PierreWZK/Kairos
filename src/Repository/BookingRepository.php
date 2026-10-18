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

    public function countForCalendar(Calendar $calendar): int
    {
        return $this->count(['calendar' => $calendar]);
    }

    /**
     * @param list<Calendar> $calendars
     *
     * @return list<Booking> Les prochains rendez-vous (en attente ou confirmés) de ces calendriers, les plus proches d'abord
     */
    public function findUpcomingFor(array $calendars, \DateTimeImmutable $from, int $limit): array
    {
        if ([] === $calendars) {
            return [];
        }

        return $this->createQueryBuilder('b')
            ->addSelect('s', 'c')
            ->join('b.service', 's')
            ->join('b.calendar', 'c')
            ->andWhere('b.calendar IN (:calendars)')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('b.endsAt > :from')
            ->setParameter('calendars', $calendars)
            ->setParameter('statuses', BookingStatus::blocking())
            ->setParameter('from', self::utc($from))
            ->orderBy('b.startsAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les rendez-vous de l'espace entreprise : à venir (les plus proches d'abord) ou passés (les plus récents d'abord).
     *
     * @param list<Calendar> $calendars
     *
     * @return list<Booking>
     */
    public function findForSpace(array $calendars, \DateTimeImmutable $now, bool $upcoming, ?BookingStatus $status, int $limit): array
    {
        if ([] === $calendars) {
            return [];
        }

        $query = $this->createQueryBuilder('b')
            ->addSelect('s', 'c')
            ->join('b.service', 's')
            ->join('b.calendar', 'c')
            ->andWhere('b.calendar IN (:calendars)')
            ->andWhere($upcoming ? 'b.endsAt > :now' : 'b.endsAt <= :now')
            ->setParameter('calendars', $calendars)
            ->setParameter('now', self::utc($now))
            ->orderBy('b.startsAt', $upcoming ? 'ASC' : 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $query->andWhere('b.status = :status')->setParameter('status', $status);
        }

        return $query->getQuery()->getResult();
    }

    /** @param list<Calendar> $calendars Le nombre de demandes à venir qui attendent une réponse */
    public function countPendingFor(array $calendars, \DateTimeImmutable $now): int
    {
        if ([] === $calendars) {
            return 0;
        }

        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.calendar IN (:calendars)')
            ->andWhere('b.status = :pending')
            ->andWhere('b.endsAt > :now')
            ->setParameter('calendars', $calendars)
            ->setParameter('pending', BookingStatus::Pending)
            ->setParameter('now', self::utc($now))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Doctrine enregistre et compare l'heure telle quelle : les bornes doivent être en UTC, comme la base */
    private static function utc(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
