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
            ->where('UNACCENT(LOWER(s.name)) LIKE UNACCENT(LOWER(:term))')
            ->andWhere('s.locationType IS NULL OR s.locationType = 0')
            ->setParameter('term', '%' . $term . '%')
            ->orderBy('s.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Quays only — parent stations (location_type 1) never carry departures. */
    public function countQuays(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.locationType IS NULL OR s.locationType = 0')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Quays attached to no StopArea. Anything above zero means an import ran
     * without StopAreaBuilder::rebuild() behind it, which silently empties
     * every /api/areas/* departures response.
     */
    public function countOrphanQuays(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.locationType IS NULL OR s.locationType = 0')
            ->andWhere('s.area IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
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
