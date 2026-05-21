<?php

namespace App\Repository\Gtfs;

use App\Entity\Gtfs\StopTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StopTime> */
class StopTimeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StopTime::class);
    }

    /**
     * Find next departures from a stop for active service IDs and a time window.
     *
     * @param string[] $activeServiceIds
     * @return StopTime[]
     */
    public function findNextDepartures(
        string $stopId,
        array $activeServiceIds,
        int $minSeconds,
        int $maxSeconds,
        int $limit = 20,
    ): array {
        if ($activeServiceIds === []) {
            return [];
        }

        return $this->createQueryBuilder('st')
            ->innerJoin('st.trip', 't')
            ->innerJoin('t.route', 'r')
            ->addSelect('t', 'r')
            ->where('st.stop = :stopId')
            ->andWhere('t.serviceId IN (:services)')
            ->andWhere('st.departureSeconds BETWEEN :minS AND :maxS')
            ->setParameter('stopId', $stopId)
            ->setParameter('services', $activeServiceIds)
            ->setParameter('minS', $minSeconds)
            ->setParameter('maxS', $maxSeconds)
            ->orderBy('st.departureSeconds', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
