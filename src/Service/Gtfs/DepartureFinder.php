<?php

namespace App\Service\Gtfs;

use App\Dto\DepartureDto;
use App\Repository\Gtfs\StopTimeRepository;
use App\Service\Gtfs\Realtime\RealtimeDepartureMerger;

/**
 * Computes the next departures from a stop using static GTFS data, then merges
 * GTFS-RT delays / cancellations on top via RealtimeDepartureMerger.
 *
 * Each departure DTO returned carries:
 *   - scheduledTime  (theoretical)
 *   - realtimeTime   (patched, null when no RT match)
 *   - minutesUntil   (always against the *real* moment)
 *   - isRealtime + delaySeconds
 *
 * Canceled trips and skipped stops are dropped before returning.
 */
final class DepartureFinder
{
    public function __construct(
        private readonly StopTimeRepository $stopTimes,
        private readonly ActiveServicesResolver $activeServices,
        private readonly RealtimeDepartureMerger $realtimeMerger,
    ) {
    }

    /**
     * @return DepartureDto[]
     */
    public function nextDepartures(string $stopId, \DateTimeImmutable $now, int $windowMinutes = 60, int $limit = 20): array
    {
        $today = $now->setTime(0, 0);
        $secondsNow = (int) $now->format('H') * 3600 + (int) $now->format('i') * 60 + (int) $now->format('s');
        $windowSeconds = $windowMinutes * 60;

        // Over-fetch: RT can drop trips (cancellations, skipped stops), so we
        // pull a few extras to still hit $limit after the merge. Capped to keep
        // the static query cheap.
        $fetchLimit = min($limit + 10, $limit * 2);

        // Today's services, looking forward in current day.
        $todayServices = $this->activeServices->resolveForDate($today);
        $todayResults = $this->stopTimes->findNextDepartures(
            $stopId,
            $todayServices,
            $secondsNow,
            $secondsNow + $windowSeconds,
            $fetchLimit,
        );

        // Late-night trips that started yesterday but continue past midnight
        // are represented with departure_seconds > 86400 against yesterday's service.
        $yesterday = $today->modify('-1 day');
        $yServices = $this->activeServices->resolveForDate($yesterday);
        $yResults = $this->stopTimes->findNextDepartures(
            $stopId,
            $yServices,
            $secondsNow + 86400,
            $secondsNow + 86400 + $windowSeconds,
            $fetchLimit,
        );

        $departures = [];
        foreach ($todayResults as $st) {
            $departures[] = DepartureDto::fromStopTime($st, $today, $now);
        }
        foreach ($yResults as $st) {
            $departures[] = DepartureDto::fromStopTime($st, $yesterday, $now);
        }

        // Apply GTFS-RT patches (delays, cancellations, skipped stops).
        // We fetch the larger initial set so RT-dropped trips don't leave us
        // short of $limit results after the merge.
        $departures = $this->realtimeMerger->merge($departures, $now);

        // Re-sort: a delayed trip can leapfrog an on-time one.
        usort($departures, fn($a, $b) => $a->minutesUntil <=> $b->minutesUntil);

        return array_slice($departures, 0, $limit);
    }
}
