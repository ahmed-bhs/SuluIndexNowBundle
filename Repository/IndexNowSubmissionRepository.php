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

    /**
     * @param array{status?: string|null, trigger?: string|null} $filters
     *
     * @return array{items: array<int, IndexNowSubmission>, total: int}
     */
    public function findPage(
        ?string $host = null,
        int $page = 1,
        int $limit = 20,
        array $filters = [],
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        $queryBuilder = $this->createLatestQueryBuilder($host);
        $this->applyFilters($queryBuilder, $filters);

        $countBuilder = clone $queryBuilder;
        $total = (int) $countBuilder
            ->select('COUNT(s.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $queryBuilder
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Aggregated health over the most recent runs, oldest bound included.
     *
     * @return array{total: int, successful: int, partial: int, failed: int, averageDurationMs: int|null}
     */
    public function findStatistics(?string $host = null, int $limit = 20): array
    {
        $submissions = $this->findHistory($host, $limit);

        $stats = [
            'total' => count($submissions),
            'successful' => 0,
            'partial' => 0,
            'failed' => 0,
            'averageDurationMs' => null,
        ];

        $durations = [];
        foreach ($submissions as $submission) {
            match ($submission->getStatus()) {
                IndexNowSubmission::STATUS_SUCCESS => $stats['successful']++,
                IndexNowSubmission::STATUS_PARTIAL => $stats['partial']++,
                default => $stats['failed']++,
            };

            if (null !== $submission->getDurationMs()) {
                $durations[] = $submission->getDurationMs();
            }
        }

        if ([] !== $durations) {
            $stats['averageDurationMs'] = (int) round(array_sum($durations) / count($durations));
        }

        return $stats;
    }

    /**
     * @param array{status?: string|null, trigger?: string|null} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $queryBuilder, array $filters): void
    {
        $status = $filters['status'] ?? null;
        if (is_string($status) && '' !== $status) {
            $queryBuilder->andWhere('s.status = :status')->setParameter('status', $status);
        }

        $trigger = $filters['trigger'] ?? null;
        if (is_string($trigger) && '' !== $trigger) {
            $queryBuilder->andWhere('s.trigger = :trigger')->setParameter('trigger', $trigger);
        }
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
