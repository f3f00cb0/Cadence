<?php

namespace App\Dto;

use App\Entity\Gtfs\StopTime;

/**
 * A computed upcoming departure event, ready for JSON serialization.
 *
 * Fields:
 *   scheduledTime  HH:MM of the theoretical (GTFS static) departure.
 *   realtimeTime   HH:MM of the actual departure after applying GTFS-RT delay.
 *                  Null when no realtime patch exists for this (trip, stop).
 *   minutesUntil   Minutes until the *real* moment (realtimeTime when RT applies,
 *                  otherwise scheduledTime). What the user sees as the count-down.
 *   isRealtime     True when a GTFS-RT trip update matched and was applied.
 *   delaySeconds   Signed delay (positive = late, negative = early). Only set
 *                  when isRealtime is true.
 *   stopId         Static GTFS stop_id this departure leaves from. Needed by
 *                  the merger to match a per-(trip, stop) RT patch.
 *   stopSequence   stop_sequence in the trip. Used as a fallback match key
 *                  when the RT feed doesn't include stop_id on a given update.
 *   serviceDay     Internal — service day this departure belongs to. Drives
 *                  start_date matching against the RT feed. Not serialized.
 */
final class DepartureDto implements \JsonSerializable
{
    public function __construct(
        public readonly string $routeId,
        public readonly ?string $routeShortName,
        public readonly ?string $routeColor,
        public readonly string $routeTypeLabel,
        public readonly ?string $headsign,
        public readonly string $scheduledTime,
        public readonly ?string $realtimeTime,
        public readonly int $minutesUntil,
        public readonly string $tripId,
        public readonly string $stopId,
        public readonly int $stopSequence,
        public readonly \DateTimeImmutable $serviceDay,
        public readonly bool $isRealtime = false,
        public readonly ?int $delaySeconds = null,
    ) {
    }

    public static function fromStopTime(StopTime $st, \DateTimeImmutable $serviceDay, \DateTimeImmutable $now): self
    {
        $secOfDay = $st->getDepartureSeconds() % 86400;
        $dayOffset = intdiv($st->getDepartureSeconds(), 86400);
        $departureMoment = $serviceDay
            ->modify("+{$dayOffset} day")
            ->setTime(
                intdiv($secOfDay, 3600),
                intdiv($secOfDay % 3600, 60),
                $secOfDay % 60,
            );
        $diffSec = $departureMoment->getTimestamp() - $now->getTimestamp();
        $minutes = max(0, (int) round($diffSec / 60));

        $trip = $st->getTrip();
        $route = $trip->getRoute();

        return new self(
            routeId: $route->getId(),
            routeShortName: $route->getShortName(),
            routeColor: $route->getColor(),
            routeTypeLabel: $route->getTypeLabel(),
            headsign: $st->getStopHeadsign() ?: $trip->getHeadsign(),
            scheduledTime: $departureMoment->format('H:i'),
            realtimeTime: null,
            minutesUntil: $minutes,
            tripId: $trip->getId(),
            stopId: $st->getStop()->getId(),
            stopSequence: $st->getStopSequence(),
            serviceDay: $serviceDay,
            isRealtime: false,
            delaySeconds: null,
        );
    }

    /**
     * Return a copy of this DTO with a GTFS-RT delay applied. The scheduled
     * time stays untouched (so the front can show "expected 09:23 · +45s")
     * while minutesUntil is recomputed against the actual moment.
     */
    public function withRealtimeDelay(int $delaySeconds, \DateTimeImmutable $now): self
    {
        [$h, $m] = array_map('intval', explode(':', $this->scheduledTime));
        $scheduled = $this->serviceDay->setTime($h, $m, 0);
        $real = $scheduled->modify(sprintf('%+d seconds', $delaySeconds));
        $diffSec = $real->getTimestamp() - $now->getTimestamp();
        $minutes = max(0, (int) round($diffSec / 60));

        return new self(
            routeId: $this->routeId,
            routeShortName: $this->routeShortName,
            routeColor: $this->routeColor,
            routeTypeLabel: $this->routeTypeLabel,
            headsign: $this->headsign,
            scheduledTime: $this->scheduledTime,
            realtimeTime: $real->format('H:i'),
            minutesUntil: $minutes,
            tripId: $this->tripId,
            stopId: $this->stopId,
            stopSequence: $this->stopSequence,
            serviceDay: $this->serviceDay,
            isRealtime: true,
            delaySeconds: $delaySeconds,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'routeId' => $this->routeId,
            'routeShortName' => $this->routeShortName,
            'routeColor' => $this->routeColor,
            'routeTypeLabel' => $this->routeTypeLabel,
            'headsign' => $this->headsign,
            'scheduledTime' => $this->scheduledTime,
            'realtimeTime' => $this->realtimeTime,
            'minutesUntil' => $this->minutesUntil,
            'tripId' => $this->tripId,
            'isRealtime' => $this->isRealtime,
            'delaySeconds' => $this->delaySeconds,
        ];
    }
}
