<?php

namespace App\Repository\Gtfs;

use App\Entity\Gtfs\Stop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Stop> */
class StopRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stop::class);
    }

    /** @return Stop[] */
    public function searchByName(string $term, int $limit = 20): array
    {
        return $this->createQueryBuilder('s')
            ->where('LOWER(s.name) LIKE :term')
            ->andWhere('s.locationType IS NULL OR s.locationType = 0')
            ->setParameter('term', '%' . strtolower($term) . '%')
            ->orderBy('s.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Bounding-box search (degrees). Cheap, no PostGIS required.
     * @return Stop[]
     */
    public function findInBoundingBox(float $minLat, float $maxLat, float $minLon, float $maxLon, int $limit = 500): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.latitude BETWEEN :minLat AND :maxLat')
            ->andWhere('s.longitude BETWEEN :minLon AND :maxLon')
            ->andWhere('s.locationType IS NULL OR s.locationType = 0')
            ->setParameter('minLat', $minLat)
            ->setParameter('maxLat', $maxLat)
            ->setParameter('minLon', $minLon)
            ->setParameter('maxLon', $maxLon)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
