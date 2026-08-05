<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\Gtfs\StopAreaRepository;
use App\Repository\Gtfs\StopRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static-GTFS integrity, meant to be polled by an uptime monitor.
 *
 * Complements /api/realtime/status, which only covers GTFS-RT freshness. The
 * failure this exists for is the quiet one: a GTFS import that truncates
 * gtfs_stop without regrouping leaves every Stop -> StopArea link null, and
 * since the whole app reads departures through StopArea::getStops(), the site
 * answers "no departures" everywhere while the areas list, the routes shown on
 * the map and the RT feed all still look perfectly green.
 */
#[Route('/api/health', name: 'api_health_')]
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly StopRepository $stops,
        private readonly StopAreaRepository $areas,
    ) {
    }

    #[Route('/gtfs', name: 'gtfs', methods: ['GET'])]
    public function gtfs(): JsonResponse
    {
        $quays = $this->stops->countQuays();
        $orphans = $this->stops->countOrphanQuays();
        $areas = $this->areas->count([]);

        $healthy = $quays > 0 && $areas > 0 && $orphans === 0;

        return $this->json(
            [
                'healthy' => $healthy,
                'stops' => $quays,
                'stops_linked' => $quays - $orphans,
                'stops_orphaned' => $orphans,
                'areas' => $areas,
            ],
            // 503 so a monitor pages on this without needing to parse the body.
            $healthy ? 200 : 503,
        );
    }
}
