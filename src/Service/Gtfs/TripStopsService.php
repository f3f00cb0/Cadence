<?php

declare(strict_types=1);

namespace App\Service\Gtfs;

use App\Entity\Gtfs\Trip;
use App\Repository\Gtfs\Realtime\TripStopUpdateRepository;
use App\Repository\Gtfs\StopTimeRepository;
use App\Service\Gtfs\Realtime\GtfsRtParser;

/**
 * Resolves the ordered upcoming stops of a single trip with GTFS-RT delays
 * applied per stop. Powers the "see the rest of this trip" timeline that
 * expands inline under a departure row.
 *
 * Unlike DepartureFinder (which scans every trip at a stop), this service
 * walks a single known trip forward from a given stop_sequence — one stop_time
 * query + one RT lookup, both indexed.
 */
final class TripStopsService
{
    public function __construct(
        private readonly StopTimeRepository $stopTimes,
        private readonly TripStopUpdateRepository $rtUpdates,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function upcomingStops(
        Trip $trip,
        \DateTimeImmutable $serviceDay,
        int $fromSequence,
        \DateTimeImmutable $now,
        int $limit = 20,
    ): array {
        $stopTimes = $this->stopTimes->findUpcomingForTrip($trip->getId(), $fromSequence, $limit);
        if ($stopTimes === []) {
            return [];
        }

        $startDate = $serviceDay->format('Ymd');
        $rtIndex = $this->rtUpdates->indexByTrip([$trip->getId()]);
        $rtEntry = $rtIndex["{$trip->getId()}|{$startDate}"]
                ?? $rtIndex["{$trip->getId()}|"]
                ?? null;

        // A canceled trip wipes the timeline entirely — the caller can render
        // an "annulé" state instead.
        if ($rtEntry !== null && $rtEntry['canceled']) {
            return [];
        }

        $out = [];
        foreach ($stopTimes as $st) {
            $secOfDay = $st->getDepartureSeconds() % 86400;
            $dayOffset = intdiv($st->getDepartureSeconds(), 86400);
            $scheduled = $serviceDay
                ->modify("+{$dayOffset} day")
                ->setTime(
                    intdiv($secOfDay, 3600),
                    intdiv($secOfDay % 3600, 60),
                    $secOfDay % 60,
                );

            $delay = null;
            $realtime = null;

            if ($rtEntry !== null) {
                $patch = $rtEntry['byStop'][$st->getStop()->getId()]
                      ?? $rtEntry['bySeq'][$st->getStopSequence()]
                      ?? null;
                if ($patch !== null) {
                    if ($patch['rel'] === GtfsRtParser::STU_SKIPPED) {
                        // RT says vehicle won't actually serve this stop — drop it from the timeline.
                        continue;
                    }
                    $delay = $patch['delay'];
                    $realtime = $scheduled->modify(sprintf('%+d seconds', $delay));
                }
            }

            $effective = $realtime ?? $scheduled;
            $diffSec = $effective->getTimestamp() - $now->getTimestamp();
            $minutesUntil = max(0, (int) round($diffSec / 60));

            $stop = $st->getStop();
            $out[] = [
                'stopId' => $stop->getId(),
                'areaId' => $stop->getArea()?->getId(),
                'name' => $stop->getName(),
                'sequence' => $st->getStopSequence(),
                'scheduledTime' => $scheduled->format('H:i'),
                'realtimeTime' => $realtime?->format('H:i'),
                'minutesUntil' => $minutesUntil,
                'delaySeconds' => $delay,
                'isRealtime' => $delay !== null,
                'isCurrent' => $st->getStopSequence() === $fromSequence,
            ];
        }

        return $out;
    }
}
