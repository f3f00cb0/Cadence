<?php

namespace App\Repository\Gtfs;

use App\Entity\Gtfs\StopArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StopArea> */
class StopAreaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StopArea::class);
    }

    /** @return StopArea[] */
    public function searchByName(string $term, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->where('LOWER(a.name) LIKE :term')
            ->setParameter('term', '%' . strtolower($term) . '%')
            ->orderBy('a.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return StopArea[] */
    public function findInBoundingBox(float $minLat, float $maxLat, float $minLon, float $maxLon, int $limit = 500): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.latitude BETWEEN :minLat AND :maxLat')
            ->andWhere('a.longitude BETWEEN :minLon AND :maxLon')
            ->setParameter('minLat', $minLat)
            ->setParameter('maxLat', $maxLat)
            ->setParameter('minLon', $minLon)
            ->setParameter('maxLon', $maxLon)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all areas ordered by haversine distance to (lat, lon).
     * Cheap enough at ~hundreds of rows; no PostGIS needed.
     *
     * @return array<array{area: StopArea, distance_m: int}>
     */
    public function findNearby(float $lat, float $lon, int $limit = 5, int $radiusMeters = 2000): array
    {
        $rough = 0.0001 * $radiusMeters / 11; // ≈ degrees envelope, generous
        $candidates = $this->createQueryBuilder('a')
            ->where('a.latitude BETWEEN :minLat AND :maxLat')
            ->andWhere('a.longitude BETWEEN :minLon AND :maxLon')
            ->setParameter('minLat', $lat - $rough)
            ->setParameter('maxLat', $lat + $rough)
            ->setParameter('minLon', $lon - $rough)
            ->setParameter('maxLon', $lon + $rough)
            ->getQuery()
            ->getResult();

        $scored = [];
        foreach ($candidates as $a) {
            $d = self::haversine($lat, $lon, $a->getLatitude(), $a->getLongitude());
            if ($d <= $radiusMeters) {
                $scored[] = ['area' => $a, 'distance_m' => (int) round($d)];
            }
        }
        usort($scored, fn($x, $y) => $x['distance_m'] <=> $y['distance_m']);

        return array_slice($scored, 0, $limit);
    }

    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
