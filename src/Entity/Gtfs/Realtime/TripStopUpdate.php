<?php

declare(strict_types=1);

namespace App\Entity\Gtfs\Realtime;

use App\Repository\Gtfs\Realtime\TripStopUpdateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single (trip, stop) realtime patch derived from the latest GTFS-RT FeedMessage.
 *
 * Replacement strategy: the whole table is wiped and re-inserted on every
 * successful fetch (TRUNCATE + bulk INSERT in one transaction). We do not keep
 * history — GTFS-RT is a "current state" feed, and stale rows are worse than
 * none (they'd say a trip is "running" hours after it's done).
 *
 * Lookup keys used by the merger:
 *   - (trip_id, start_date, stop_id)      ← preferred
 *   - (trip_id, start_date, stop_sequence) ← fallback
 *   - (trip_id, start_date) with trip_canceled=true ← drops all stops of a trip
 */
#[ORM\Entity(repositoryClass: TripStopUpdateRepository::class)]
#[ORM\Table(name: 'gtfs_rt_trip_update')]
#[ORM\Index(name: 'idx_rt_trip_stop', columns: ['trip_id', 'start_date', 'stop_id'])]
#[ORM\Index(name: 'idx_rt_trip_seq',  columns: ['trip_id', 'start_date', 'stop_sequence'])]
class TripStopUpdate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tripId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $routeId;

    /** YYYYMMDD of the service day this update applies to. Null = today (best-effort). */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $startDate;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stopId;

    #[ORM\Column(nullable: true)]
    private ?int $stopSequence;

    /** Signed delay in seconds. Positive = late, negative = early. */
    #[ORM\Column]
    private int $delaySeconds;

    /** GTFS-RT StopTimeUpdate.ScheduleRelationship (0=SCHEDULED, 1=SKIPPED, 2=NO_DATA, 3=UNSCHEDULED). */
    #[ORM\Column(type: 'smallint')]
    private int $scheduleRelationship;

    /** True when the entire trip is canceled (TripDescriptor.ScheduleRelationship = CANCELED/DELETED). */
    #[ORM\Column]
    private bool $tripCanceled;

    public function __construct(
        ?string $tripId,
        ?string $routeId,
        ?string $startDate,
        ?string $stopId,
        ?int $stopSequence,
        int $delaySeconds,
        int $scheduleRelationship,
        bool $tripCanceled,
    ) {
        $this->tripId = $tripId;
        $this->routeId = $routeId;
        $this->startDate = $startDate;
        $this->stopId = $stopId;
        $this->stopSequence = $stopSequence;
        $this->delaySeconds = $delaySeconds;
        $this->scheduleRelationship = $scheduleRelationship;
        $this->tripCanceled = $tripCanceled;
    }

    public function getId(): ?int { return $this->id; }
    public function getTripId(): ?string { return $this->tripId; }
    public function getRouteId(): ?string { return $this->routeId; }
    public function getStartDate(): ?string { return $this->startDate; }
    public function getStopId(): ?string { return $this->stopId; }
    public function getStopSequence(): ?int { return $this->stopSequence; }
    public function getDelaySeconds(): int { return $this->delaySeconds; }
    public function getScheduleRelationship(): int { return $this->scheduleRelationship; }
    public function isTripCanceled(): bool { return $this->tripCanceled; }
}
