<?php

declare(strict_types=1);

namespace App\Service\Gtfs\Realtime;

/**
 * Decodes a GTFS-Realtime FeedMessage protobuf payload and yields TripStopUpdateRow
 * value objects, ready to be persisted.
 *
 * Reference proto: https://github.com/google/transit/blob/master/gtfs-realtime/proto/gtfs-realtime.proto
 *
 * We only decode the subset needed to merge delays into theoretical departures:
 *
 *   FeedMessage {
 *       FeedHeader header = 1;                  // skipped
 *       repeated FeedEntity entity = 2;
 *   }
 *
 *   FeedEntity {
 *       string id = 1;
 *       bool   is_deleted = 2;
 *       TripUpdate trip_update = 3;
 *       // vehicle/alert/shape ignored
 *   }
 *
 *   TripUpdate {
 *       TripDescriptor trip = 1;
 *       repeated StopTimeUpdate stop_time_update = 2;
 *       // vehicle, timestamp, delay, trip_properties ignored
 *   }
 *
 *   TripDescriptor {
 *       string trip_id    = 1;
 *       string route_id   = 5;
 *       string start_time = 2;          // ignored — keeping start_date is enough
 *       string start_date = 3;          // YYYYMMDD
 *       ScheduleRelationship schedule_relationship = 4;
 *   }
 *
 *   StopTimeUpdate {
 *       uint32 stop_sequence = 1;
 *       string stop_id       = 4;
 *       StopTimeEvent arrival   = 2;
 *       StopTimeEvent departure = 3;
 *       ScheduleRelationship schedule_relationship = 5;
 *   }
 *
 *   StopTimeEvent {
 *       int32 delay = 1;       // seconds, can be negative
 *       int64 time  = 2;       // POSIX seconds
 *       int32 uncertainty = 3; // ignored
 *   }
 */
final class GtfsRtParser
{
    /** TripDescriptor.ScheduleRelationship */
    public const TRIP_SCHEDULED   = 0;
    public const TRIP_ADDED       = 1;
    public const TRIP_UNSCHEDULED = 2;
    public const TRIP_CANCELED    = 3;
    public const TRIP_REPLACEMENT = 4;
    public const TRIP_DUPLICATED  = 5;
    public const TRIP_DELETED     = 6;

    /** StopTimeUpdate.ScheduleRelationship */
    public const STU_SCHEDULED = 0;
    public const STU_SKIPPED   = 1;
    public const STU_NO_DATA   = 2;
    public const STU_UNSCHEDULED = 3;

    /**
     * @return TripStopUpdateRow[]
     */
    public function parse(string $payload): array
    {
        $reader = new ProtobufReader($payload);
        $rows = [];

        while (($tag = $reader->readTag()) !== null) {
            [$field, $wire] = $tag;
            if ($field === 2 && $wire === ProtobufReader::WIRE_LEN) {
                // FeedEntity
                $this->parseFeedEntity($reader->readBytes(), $rows);
            } else {
                $reader->skipField($wire);
            }
        }

        return $rows;
    }

    /** @param TripStopUpdateRow[] $rows */
    private function parseFeedEntity(string $bytes, array &$rows): void
    {
        $reader = new ProtobufReader($bytes);
        $isDeleted = false;
        $tripUpdateBytes = null;

        while (($tag = $reader->readTag()) !== null) {
            [$field, $wire] = $tag;
            if ($field === 2 && $wire === ProtobufReader::WIRE_VARINT) {
                $isDeleted = $reader->readBool();
            } elseif ($field === 3 && $wire === ProtobufReader::WIRE_LEN) {
                $tripUpdateBytes = $reader->readBytes();
            } else {
                $reader->skipField($wire);
            }
        }

        if ($isDeleted || $tripUpdateBytes === null) {
            return;
        }

        $this->parseTripUpdate($tripUpdateBytes, $rows);
    }

    /** @param TripStopUpdateRow[] $rows */
    private function parseTripUpdate(string $bytes, array &$rows): void
    {
        $reader = new ProtobufReader($bytes);
        $tripId = null;
        $routeId = null;
        $startDate = null;
        $tripRel = self::TRIP_SCHEDULED;
        $stuPayloads = [];

        while (($tag = $reader->readTag()) !== null) {
            [$field, $wire] = $tag;
            if ($field === 1 && $wire === ProtobufReader::WIRE_LEN) {
                // TripDescriptor
                $this->parseTripDescriptor($reader->readBytes(), $tripId, $routeId, $startDate, $tripRel);
            } elseif ($field === 2 && $wire === ProtobufReader::WIRE_LEN) {
                $stuPayloads[] = $reader->readBytes();
            } else {
                $reader->skipField($wire);
            }
        }

        // No usable identifier — skip.
        if ($tripId === null && $routeId === null) {
            return;
        }

        // If the whole trip is canceled, emit a single sentinel row so the merger
        // can drop every theoretical departure for that trip on that service day.
        if ($tripRel === self::TRIP_CANCELED || $tripRel === self::TRIP_DELETED) {
            $rows[] = new TripStopUpdateRow(
                tripId: $tripId,
                routeId: $routeId,
                startDate: $startDate,
                stopId: null,
                stopSequence: null,
                delaySeconds: 0,
                scheduleRelationship: self::STU_SKIPPED,
                tripCanceled: true,
            );
            return;
        }

        foreach ($stuPayloads as $stuBytes) {
            $row = $this->parseStopTimeUpdate($stuBytes, $tripId, $routeId, $startDate);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
    }

    private function parseTripDescriptor(
        string $bytes,
        ?string &$tripId,
        ?string &$routeId,
        ?string &$startDate,
        int &$relationship,
    ): void {
        $reader = new ProtobufReader($bytes);
        while (($tag = $reader->readTag()) !== null) {
            [$field, $wire] = $tag;
            match ($field) {
                1 => $tripId   = $reader->readBytes(),
                3 => $startDate = $reader->readBytes(),
                4 => $relationship = $reader->readVarint(),
                5 => $routeId  = $reader->readBytes(),
                default => $reader->skipField($wire),
            };
        }
    }

    private function parseStopTimeUpdate(string $bytes, ?string $tripId, ?string $routeId, ?string $startDate): ?TripStopUpdateRow
    {
        $reader = new ProtobufReader($bytes);
        $stopSequence = null;
        $stopId = null;
        $arrivalDelay = null;
        $departureDelay = null;
        $rel = self::STU_SCHEDULED;

        while (($tag = $reader->readTag()) !== null) {
            [$field, $wire] = $tag;
            switch ($field) {
                case 1:
                    $stopSequence = $reader->readVarint();
                    break;
                case 2:
                    $arrivalDelay = $this->parseStopTimeEvent($reader->readBytes());
                    break;
                case 3:
                    $departureDelay = $this->parseStopTimeEvent($reader->readBytes());
                    break;
                case 4:
                    $stopId = $reader->readBytes();
                    break;
                case 5:
                    $rel = $reader->readVarint();
                    break;
                default:
                    $reader->skipField($wire);
            }
        }

        if ($stopId === null && $stopSequence === null) {
            return null;
        }

        // Prefer departure delay (what the user actually waits for at a stop)
        // and fall back to arrival.
        $delay = $departureDelay ?? $arrivalDelay ?? 0;

        return new TripStopUpdateRow(
            tripId: $tripId,
            routeId: $routeId,
            startDate: $startDate,
            stopId: $stopId,
            stopSequence: $stopSequence,
            delaySeconds: $delay,
            scheduleRelationship: $rel,
            tripCanceled: false,
        );
    }

    private function parseStopTimeEvent(string $bytes): ?int
    {
        $reader = new ProtobufReader($bytes);
        $delay = null;
        while (($tag = $reader->readTag()) !== null) {
            [$field, $wire] = $tag;
            if ($field === 1 && $wire === ProtobufReader::WIRE_VARINT) {
                // int32 delay — signed varint. PHP 64-bit handles negative naturally
                // because the proto encoder sign-extends to 10 bytes for negatives.
                $v = $reader->readVarint();
                // Re-interpret as signed 32-bit if value looks like an unsigned
                // representation of a negative number.
                if ($v >= 0x80000000 && $v < 0x100000000) {
                    $v -= 0x100000000;
                }
                $delay = $v;
            } else {
                $reader->skipField($wire);
            }
        }
        return $delay;
    }
}
