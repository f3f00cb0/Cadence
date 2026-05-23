<?php

namespace App\Controller\Api;

use App\Repository\Gtfs\TripRepository;
use App\Service\Gtfs\TripStopsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/trips', name: 'api_trips_')]
final class TripsController extends AbstractController
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly TripStopsService $tripStops,
    ) {
    }

    /**
     * Returns the upcoming stops of a trip starting from a given stop_sequence,
     * each annotated with the RT-patched ETA. Powers the inline timeline that
     * unfolds under a departure row when the user wants to see "what's next".
     */
    #[Route('/{tripId}/upcoming-stops', name: 'upcoming_stops', methods: ['GET'], requirements: ['tripId' => '.+'])]
    public function upcomingStops(string $tripId, Request $request): JsonResponse
    {
        $trip = $this->trips->find($tripId);
        if (!$trip) {
            return $this->json(['error' => 'Trip not found'], 404);
        }

        $fromSequence = max(0, (int) $request->query->get('fromSequence', 0));
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));

        $tz = new \DateTimeZone('Europe/Paris');
        $now = new \DateTimeImmutable('now', $tz);

        $serviceDayStr = trim((string) $request->query->get('serviceDay', ''));
        if ($serviceDayStr !== '') {
            $serviceDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $serviceDayStr, $tz);
            if (!$serviceDay) {
                return $this->json(['error' => 'Invalid serviceDay (expected YYYY-MM-DD)'], 400);
            }
        } else {
            $serviceDay = $now->setTime(0, 0);
        }

        $stops = $this->tripStops->upcomingStops($trip, $serviceDay, $fromSequence, $now, $limit);

        $route = $trip->getRoute();
        return $this->json([
            'trip' => [
                'id' => $trip->getId(),
                'routeId' => $route->getId(),
                'routeShortName' => $route->getShortName(),
                'routeColor' => $route->getColor(),
                'routeTextColor' => $route->getTextColor(),
                'routeTypeLabel' => $route->getTypeLabel(),
                'headsign' => $trip->getHeadsign(),
            ],
            'stops' => $stops,
        ]);
    }
}
