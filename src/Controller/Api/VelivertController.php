<?php

namespace App\Controller\Api;

use App\Entity\Velivert\Station;
use App\Repository\Velivert\StationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/velivert', name: 'api_velivert_')]
final class VelivertController extends AbstractController
{
    public function __construct(private readonly StationRepository $stations)
    {
    }

    #[Route('/stations', name: 'stations', methods: ['GET'])]
    public function stations(): JsonResponse
    {
        $stations = array_map(
            self::serializeStation(...),
            $this->stations->findAllOrdered(),
        );

        return $this->json([
            'stations' => $stations,
            'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/nearby', name: 'nearby', methods: ['GET'])]
    public function nearby(Request $request): JsonResponse
    {
        $lat = (float) $request->query->get('lat');
        $lon = (float) $request->query->get('lon');
        $limit = max(1, min(20, (int) $request->query->get('limit', 5)));
        $radius = max(100, min(10000, (int) $request->query->get('radius', 3000)));

        if ($lat === 0.0 || $lon === 0.0) {
            return $this->json(['error' => 'lat and lon are required'], 400);
        }

        $results = array_map(
            fn(array $row) => self::serializeStation($row['station']) + [
                'distance_m' => $row['distance_m'],
                'walk_minutes' => max(1, (int) round($row['distance_m'] / 80)),
            ],
            $this->stations->findNearby($lat, $lon, $limit, $radius),
        );

        return $this->json(['results' => $results]);
    }

    public static function serializeStation(Station $s): array
    {
        return [
            'id' => $s->getId(),
            'name' => $s->getName(),
            'address' => $s->getAddress(),
            'lat' => $s->getLatitude(),
            'lon' => $s->getLongitude(),
            'capacity' => $s->getCapacity(),
            'bikes' => $s->getBikesAvailable(),
            'docks' => $s->getDocksAvailable(),
            'operational' => $s->isInstalled() && $s->isRenting(),
            'last_reported' => $s->getLastReportedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
