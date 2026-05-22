<?php

declare(strict_types=1);

namespace App\Service\Gtfs\Realtime;

use App\Dto\DepartureDto;
use App\Repository\Gtfs\Realtime\TripStopUpdateRepository;

/**
 * Patches a list of theoretical DepartureDtos with the latest GTFS-RT data:
 *
 *  - drops departures whose trip is canceled (TripDescriptor.SCHEDULED = CANCELED)
 *  - drops departures whose StopTimeUpdate is SKIPPED
 *  - applies the (signed) delay from departure StopTimeEvent.delay onto
 *    scheduledTime → realtimeTime, recomputing minutesUntil
 *
 * Match priority:
 *  1. (trip_id, start_date, stop_id)
 *  2. (trip_id, start_date, stop_sequence)
 *  3. (trip_id) only, when the feed omits start_date (the STAS feed usually does)
 *
 * Designed to be called once with the full batch of DTOs the caller is about
 * to return — runs one DB query and is O(n) in the number of departures.
 */
final class RealtimeDepartureMerger
{
    public function __construct(private readonly TripStopUpdateRepository $updates)
    {
    }

    /**
     * @param  DepartureDto[] $departures
     * @return DepartureDto[]
     */
    public function merge(array $departures, \DateTimeImmutable $now): array
    {
        if ($departures === []) {
            return [];
        }

        $tripIds = [];
        foreach ($departures as $d) {
            $tripIds[$d->tripId] = true;
        }

        $index = $this->updates->indexByTrip(array_keys($tripIds));
        if ($index === []) {
            return $departures;
        }

        $out = [];
        foreach ($departures as $d) {
            $startDate = $d->serviceDay->format('Ymd');

            // Try exact-date match first, then fall back to a "no start_date"
            // bucket (key = "tripId|") that holds RT rows where the feed didn't
            // populate start_date.
            $entry = $index["{$d->tripId}|{$startDate}"]
                  ?? $index["{$d->tripId}|"]
                  ?? null;

            if ($entry === null) {
                $out[] = $d;
                continue;
            }

            if ($entry['canceled']) {
                continue; // drop this departure entirely
            }

            $patch = $entry['byStop'][$d->stopId]
                  ?? $entry['bySeq'][$d->stopSequence]
                  ?? null;

            if ($patch === null) {
                // Trip has RT data but no patch for this specific stop — keep
                // theoretical. Could happen for stops upstream of the vehicle.
                $out[] = $d;
                continue;
            }

            if ($patch['rel'] === GtfsRtParser::STU_SKIPPED) {
                continue; // skipped at this stop
            }

            $out[] = $d->withRealtimeDelay($patch['delay'], $now);
        }

        return $out;
    }
}
