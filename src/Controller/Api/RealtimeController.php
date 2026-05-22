<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Gtfs\Realtime\FeedStatus;
use App\Repository\Gtfs\Realtime\FeedStatusRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Surfaces the freshness of the GTFS-Realtime feed so the front can advertise
 * "TEMPS RÉEL" honestly (or downgrade to "théorique" when the feed is stale
 * or repeatedly failing).
 */
#[Route('/api/realtime', name: 'api_realtime_')]
final class RealtimeController extends AbstractController
{
    public function __construct(private readonly FeedStatusRepository $feedStatus)
    {
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $status = $this->feedStatus->find(FeedStatus::ID);
        $now = new \DateTimeImmutable();

        if ($status === null || $status->getLastSuccessAt() === null) {
            return $this->json([
                'available' => false,
                'fresh' => false,
                'last_success_at' => null,
                'last_attempt_at' => $status?->getLastAttemptAt()?->format(\DateTimeInterface::ATOM),
                'age_seconds' => null,
                'consecutive_failures' => $status?->getConsecutiveFailures() ?? 0,
                'rows' => 0,
            ]);
        }

        $age = $now->getTimestamp() - $status->getLastSuccessAt()->getTimestamp();

        return $this->json([
            'available' => true,
            // Feed is considered "fresh" if the last successful fetch was less
            // than 120 s ago — twice the scheduler interval, tolerant to one
            // missed beat.
            'fresh' => $age < 120,
            'last_success_at' => $status->getLastSuccessAt()->format(\DateTimeInterface::ATOM),
            'last_attempt_at' => $status->getLastAttemptAt()?->format(\DateTimeInterface::ATOM),
            'age_seconds' => $age,
            'consecutive_failures' => $status->getConsecutiveFailures(),
            'rows' => $status->getLastRowCount(),
        ]);
    }
}
