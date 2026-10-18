<?php

namespace App\Repository;

use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Company>
 */
class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    /** L'entreprise publique à cette adresse (null si inconnue ou suspendue) */
    public function findPublicBySlug(string $slug): ?Company
    {
        return $this->findOneBy(['slug' => $slug, 'isSuspended' => false]);
    }

    public function countCreatedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.createdAt >= :since')
            ->setParameter('since', $since->setTimezone(new \DateTimeZone('UTC')))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
