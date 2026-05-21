<?php

namespace App\Controller\Api;

use App\Repository\Gtfs\StopAreaRepository;
use App\Repository\Gtfs\StopRepository;
use App\Service\Gtfs\DepartureFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legacy v1 endpoints — kept as deprecated aliases so external consumers don't
 * break. New clients should consume /api/areas/* instead. /search and /in-bbox
 * now return areas (so the search UX matches the rest of the app); the
 * /{id}/departures endpoint still resolves a single Stop quay for debugging.
 */
#[Route('/api/stops', name: 'api_stops_')]
final class StopsController extends AbstractController
{
    public function __construct(
        private readonly StopRepository $stops,
        private readonly StopAreaRepository $areas,
        private readonly DepartureFinder $departureFinder,
    ) {
    }

    /** @deprecated use /api/areas/search */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (\strlen($q) < 2) {
            return $this->json(['results' => [], 'deprecated' => 'use /api/areas/search']);
        }

        $results = array_map(
            fn($a) => [
                'id' => $a->getId(),
                'name' => $a->getName(),
                'lat' => $a->getLatitude(),
                'lon' => $a->getLongitude(),
            ],
            $this->areas->searchByName($q, 15),
        );

        return $this->json(['results' => $results, 'deprecated' => 'use /api/areas/search']);
    }

    /** @deprecated use /api/areas/in-bbox */
    #[Route('/in-bbox', name: 'in_bbox', methods: ['GET'])]
    public function inBoundingBox(Request $request): JsonResponse
    {
        $minLat = (float) $request->query->get('minLat');
        $maxLat = (float) $request->query->get('maxLat');
        $minLon = (float) $request->query->get('minLon');
        $maxLon = (float) $request->query->get('maxLon');

        $results = array_map(
            fn($a) => [
                'id' => $a->getId(),
                'name' => $a->getName(),
                'lat' => $a->getLatitude(),
                'lon' => $a->getLongitude(),
            ],
            $this->areas->findInBoundingBox($minLat, $maxLat, $minLon, $maxLon),
        );

        return $this->json(['results' => $results, 'deprecated' => 'use /api/areas/in-bbox']);
    }

    /**
     * Per-quay departures — kept first-class because it's useful for debug and
     * for the "individual quays" detail panel in the board UI.
     */
    #[Route('/{id}/departures', name: 'departures', methods: ['GET'])]
    public function departures(string $id, Request $request): JsonResponse
    {
        $stop = $this->stops->find($id);
        if (!$stop) {
            return $this->json(['error' => 'Stop not found'], 404);
        }

        $window = max(5, min(180, (int) $request->query->get('window', 60)));
        $limit = max(1, min(50, (int) $request->query->get('limit', 15)));

        $departures = $this->departureFinder->nextDepartures(
            $stop->getId(),
            new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')),
            $window,
            $limit,
        );

        return $this->json([
            'stop' => [
                'id' => $stop->getId(),
                'name' => $stop->getName(),
                'lat' => $stop->getLatitude(),
                'lon' => $stop->getLongitude(),
                'area_id' => $stop->getArea()?->getId(),
            ],
            'departures' => $departures,
        ]);
    }
}
