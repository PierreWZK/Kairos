<?php

namespace App\Repository;

use App\Entity\Booking;
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
    /** @return list<Booking> Les rendez-vous confirmés qui chevauchent la période [from, to[ */
    public function findConfirmedOverlapping(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.startsAt < :to')
            ->andWhere('b.endsAt > :from')
            ->setParameter('status', BookingStatus::Confirmed)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
