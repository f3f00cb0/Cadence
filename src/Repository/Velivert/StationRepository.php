<?php

namespace App\Repository\Velivert;

use App\Entity\Velivert\Station;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Station> */
class StationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Station::class);
    }

    /** @return Station[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<array{station: Station, distance_m: int}>
     */
    public function findNearby(float $lat, float $lon, int $limit = 5, int $radiusMeters = 3000): array
    {
        /** @var Station[] $all */
        $all = $this->createQueryBuilder('s')->getQuery()->getResult();
        $scored = [];
        foreach ($all as $s) {
            $d = self::haversine($lat, $lon, $s->getLatitude(), $s->getLongitude());
            if ($d <= $radiusMeters) {
                $scored[] = ['station' => $s, 'distance_m' => (int) round($d)];
            }
        }
        usort($scored, fn($a, $b) => $a['distance_m'] <=> $b['distance_m']);
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
