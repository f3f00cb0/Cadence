<?php

namespace App\Dto;

use App\Entity\Gtfs\StopTime;

/**
 * A computed upcoming departure event, ready for JSON serialization.
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
        public readonly int $minutesUntil,
        public readonly string $tripId,
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
            minutesUntil: $minutes,
            tripId: $trip->getId(),
        );
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
