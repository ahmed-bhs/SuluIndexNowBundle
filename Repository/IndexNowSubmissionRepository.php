<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Linderp\SuluIndexNowBundle\Entity\IndexNowSubmission;

/**
 * @extends ServiceEntityRepository<IndexNowSubmission>
 */
class IndexNowSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndexNowSubmission::class);
    }

    public function save(IndexNowSubmission $submission): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($submission);
        $entityManager->flush();
    }

    public function findLast(?string $host = null): ?IndexNowSubmission
    {
        return $this->createLatestQueryBuilder($host)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLastSuccessful(?string $host = null): ?IndexNowSubmission
    {
        return $this->createLatestQueryBuilder($host)
            ->andWhere('s.status = :status')
            ->setParameter('status', IndexNowSubmission::STATUS_SUCCESS)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array<int, IndexNowSubmission>
     */
    public function findHistory(?string $host = null, int $limit = 20): array
    {
        return $this->createLatestQueryBuilder($host)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function createLatestQueryBuilder(?string $host): \Doctrine\ORM\QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->orderBy('s.submittedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC');

        if (null !== $host && '' !== $host) {
            $queryBuilder->andWhere('s.host = :host')->setParameter('host', $host);
        }

        return $queryBuilder;
    }
}
