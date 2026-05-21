<?php

namespace App\Controller\Api;

use App\Entity\Gtfs\StopArea;
use App\Repository\Gtfs\StopAreaRepository;
use App\Service\Gtfs\AreaDepartureAggregator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/areas', name: 'api_areas_')]
final class AreasController extends AbstractController
{
    public function __construct(
        private readonly StopAreaRepository $areas,
        private readonly AreaDepartureAggregator $aggregator,
    ) {
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (\strlen($q) < 2) {
            return $this->json(['results' => []]);
        }

        $results = array_map(self::serializeArea(...), $this->areas->searchByName($q, 15));
        return $this->json(['results' => $results]);
    }

    #[Route('/in-bbox', name: 'in_bbox', methods: ['GET'])]
    public function inBoundingBox(Request $request): JsonResponse
    {
        $minLat = (float) $request->query->get('minLat');
        $maxLat = (float) $request->query->get('maxLat');
        $minLon = (float) $request->query->get('minLon');
        $maxLon = (float) $request->query->get('maxLon');

        $results = array_map(
            self::serializeArea(...),
            $this->areas->findInBoundingBox($minLat, $maxLat, $minLon, $maxLon),
        );
        return $this->json(['results' => $results]);
    }

    #[Route('/nearby', name: 'nearby', methods: ['GET'])]
    public function nearby(Request $request): JsonResponse
    {
        $lat = (float) $request->query->get('lat');
        $lon = (float) $request->query->get('lon');
        $limit = max(1, min(20, (int) $request->query->get('limit', 5)));
        $radius = max(100, min(10000, (int) $request->query->get('radius', 2000)));

        if ($lat === 0.0 || $lon === 0.0) {
            return $this->json(['error' => 'lat and lon are required'], 400);
        }

        $rows = $this->areas->findNearby($lat, $lon, $limit, $radius);
        $results = array_map(
            fn(array $row) => self::serializeArea($row['area']) + [
                'distance_m' => $row['distance_m'],
                'walk_minutes' => max(1, (int) round($row['distance_m'] / 80)),
            ],
            $rows,
        );

        return $this->json(['results' => $results]);
    }

    #[Route('/{id}/departures', name: 'departures', methods: ['GET'])]
    public function departures(string $id, Request $request): JsonResponse
    {
        $area = $this->areas->find($id);
        if (!$area) {
            return $this->json(['error' => 'Stop area not found'], 404);
        }

        $window = max(5, min(180, (int) $request->query->get('window', 60)));
        $limit = max(1, min(50, (int) $request->query->get('limit', 15)));

        $departures = $this->aggregator->nextDeparturesForArea(
            $area,
            new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')),
            $window,
            $limit,
        );

        return $this->json([
            'area' => self::serializeArea($area) + [
                'stops' => array_map(
                    fn($s) => ['id' => $s->getId(), 'name' => $s->getName(), 'lat' => $s->getLatitude(), 'lon' => $s->getLongitude()],
                    $area->getStops()->toArray(),
                ),
            ],
            'departures' => $departures,
        ]);
    }

    #[Route('/batch-departures', name: 'batch_departures', methods: ['POST'])]
    public function batchDepartures(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $ids = $payload['ids'] ?? null;
        if (!\is_array($ids) || $ids === []) {
            return $this->json(['error' => 'ids[] required'], 400);
        }
        if (\count($ids) > 20) {
            return $this->json(['error' => 'too many ids (max 20)'], 400);
        }

        $window = max(5, min(180, (int) ($payload['window'] ?? 60)));
        $limit = max(1, min(15, (int) ($payload['limit'] ?? 6)));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        $byId = [];
        $areas = $this->areas->findBy(['id' => $ids]);
        foreach ($areas as $area) {
            $byId[$area->getId()] = [
                'area' => self::serializeArea($area),
                'departures' => $this->aggregator->nextDeparturesForArea($area, $now, $window, $limit),
            ];
        }

        return $this->json(['results' => $byId]);
    }

    /**
     * @internal Exposed as a static for re-use by alias controllers.
     */
    public static function serializeArea(StopArea $a): array
    {
        return [
            'id' => $a->getId(),
            'name' => $a->getName(),
            'lat' => $a->getLatitude(),
            'lon' => $a->getLongitude(),
            'radius_m' => $a->getBoundingRadius(),
        ];
    }
}
