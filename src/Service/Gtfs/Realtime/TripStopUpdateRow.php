<?php

declare(strict_types=1);

namespace App\Service\Gtfs\Realtime;

/**
 * A single (trip, stop) realtime update extracted from a GTFS-RT FeedMessage,
 * ready to be persisted. Plain value object, no Doctrine annotations.
 *
 * If $tripCanceled is true, this row represents a full-trip cancellation and
 * $stopId/$stopSequence will be null — the merger should drop every theoretical
 * departure matching ($tripId, $startDate).
 */
final class TripStopUpdateRow
{
    public function __construct(
        public readonly ?string $tripId,
        public readonly ?string $routeId,
        public readonly ?string $startDate,
        public readonly ?string $stopId,
        public readonly ?int    $stopSequence,
        public readonly int     $delaySeconds,
        public readonly int     $scheduleRelationship,
        public readonly bool    $tripCanceled,
    ) {
    }
}
