<?php

namespace App\Service\Gtfs;

use App\Dto\DepartureDto;
use App\Entity\Gtfs\StopArea;

/**
 * Merges next-departure lists from every Stop of a StopArea, deduplicating
 * runs of the same route/headsign/direction so users see one line per direction.
 */
final class AreaDepartureAggregator
{
    public function __construct(private readonly DepartureFinder $finder)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nextDeparturesForArea(
        StopArea $area,
        \DateTimeImmutable $now,
        int $windowMinutes,
        int $limit,
    ): array {
        $merged = [];

        foreach ($area->getStops() as $stop) {
            $deps = $this->finder->nextDepartures($stop->getId(), $now, $windowMinutes, $limit);
            foreach ($deps as $dep) {
                $key = $dep->routeId . '|' . ($dep->headsign ?? '') . '|' . $dep->scheduledTime;
                $existing = $merged[$key] ?? null;
                if ($existing === null || $dep->minutesUntil < $existing['minutesUntil']) {
                    $merged[$key] = $this->serialize($dep, $stop->getId());
                }
            }
        }

        usort($merged, fn($a, $b) => $a['minutesUntil'] <=> $b['minutesUntil']);

        // Second pass: dedupe by (routeId, headsign) keeping the soonest one.
        $seen = [];
        $out = [];
        foreach ($merged as $row) {
            $k = $row['routeId'] . '|' . ($row['headsign'] ?? '');
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $row;
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function serialize(DepartureDto $d, string $stopId): array
    {
        return [
            'routeId' => $d->routeId,
            'routeShortName' => $d->routeShortName,
            'routeColor' => $d->routeColor,
            'routeTypeLabel' => $d->routeTypeLabel,
            'headsign' => $d->headsign,
            'direction_label' => self::directionLabel($d->headsign),
            'scheduledTime' => $d->scheduledTime,
            'realtimeTime' => $d->realtimeTime,
            'minutesUntil' => $d->minutesUntil,
            'isRealtime' => $d->isRealtime,
            'delaySeconds' => $d->delaySeconds,
            'tripId' => $d->tripId,
            'stopId' => $stopId,
            'stopSequence' => $d->stopSequence,
            'serviceDay' => $d->serviceDay->format('Y-m-d'),
        ];
    }

    /**
     * Build a short "direction" label from a GTFS headsign.
     * Headsigns can look like "Châteaucreux", "T1 → La Terrasse", "Hôpital Nord via Bellevue".
     */
    public static function directionLabel(?string $headsign): ?string
    {
        if ($headsign === null || $headsign === '') {
            return null;
        }
        // Strip leading route code "T1 → ", "B3 - ", etc.
        $label = preg_replace('/^[A-Z]?\d+\s*[-→»>:]\s*/u', '', $headsign);
        // Strip "via …" tails.
        $label = preg_replace('/\s+via\s+.+$/iu', '', $label ?? $headsign);
        return trim($label ?? $headsign);
    }
}
