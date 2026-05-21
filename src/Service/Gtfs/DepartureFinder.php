<?php

namespace App\Service\Gtfs;

use App\Dto\DepartureDto;
use App\Repository\Gtfs\StopTimeRepository;

/**
 * Computes the next departures from a stop using static GTFS data only.
 * Real-time delays (GTFS-RT) will be merged in a future iteration.
 */
final class DepartureFinder
{
    public function __construct(
        private readonly StopTimeRepository $stopTimes,
        private readonly ActiveServicesResolver $activeServices,
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

        // Today's services, looking forward in current day.
        $todayServices = $this->activeServices->resolveForDate($today);
        $todayResults = $this->stopTimes->findNextDepartures(
            $stopId,
            $todayServices,
            $secondsNow,
            $secondsNow + $windowSeconds,
            $limit,
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
            $limit,
        );

        $departures = [];

        foreach ($todayResults as $st) {
            $departures[] = DepartureDto::fromStopTime($st, $today, $now);
        }
        foreach ($yResults as $st) {
            $departures[] = DepartureDto::fromStopTime($st, $yesterday, $now);
        }

        usort($departures, fn($a, $b) => $a->minutesUntil <=> $b->minutesUntil);

        return array_slice($departures, 0, $limit);
    }
}
