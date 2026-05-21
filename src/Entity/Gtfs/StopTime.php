<?php

namespace App\Entity\Gtfs;

use App\Repository\Gtfs\StopTimeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * GTFS stop_time: an event of a Trip arriving/departing a Stop.
 * Times are stored as seconds-since-noon (can exceed 86400 for trips spanning midnight).
 */
#[ORM\Entity(repositoryClass: StopTimeRepository::class)]
#[ORM\Table(name: 'gtfs_stop_time')]
#[ORM\Index(name: 'idx_stoptime_stop_dep', columns: ['stop_id', 'departure_seconds'])]
#[ORM\Index(name: 'idx_stoptime_trip', columns: ['trip_id', 'stop_sequence'])]
class StopTime
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Trip::class, inversedBy: 'stopTimes')]
    #[ORM\JoinColumn(name: 'trip_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Trip $trip;

    #[ORM\ManyToOne(targetEntity: Stop::class, inversedBy: 'stopTimes')]
    #[ORM\JoinColumn(name: 'stop_id', referencedColumnName: 'id', nullable: false)]
    private Stop $stop;

    #[ORM\Column]
    private int $stopSequence;

    /** Seconds since noon-12h of the service day. Can exceed 86400. */
    #[ORM\Column]
    private int $arrivalSeconds;

    #[ORM\Column]
    private int $departureSeconds;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stopHeadsign = null;

    public function __construct(Trip $trip, Stop $stop, int $stopSequence, int $arrivalSeconds, int $departureSeconds)
    {
        $this->trip = $trip;
        $this->stop = $stop;
        $this->stopSequence = $stopSequence;
        $this->arrivalSeconds = $arrivalSeconds;
        $this->departureSeconds = $departureSeconds;
    }

    public static function parseGtfsTime(string $hhmmss): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hhmmss));
        return $h * 3600 + $m * 60 + $s;
    }

    public function getId(): ?int { return $this->id; }
    public function getTrip(): Trip { return $this->trip; }
    public function getStop(): Stop { return $this->stop; }
    public function getStopSequence(): int { return $this->stopSequence; }
    public function getArrivalSeconds(): int { return $this->arrivalSeconds; }
    public function getDepartureSeconds(): int { return $this->departureSeconds; }
    public function getStopHeadsign(): ?string { return $this->stopHeadsign; }
    public function setStopHeadsign(?string $stopHeadsign): self { $this->stopHeadsign = $stopHeadsign; return $this; }

    public function getFormattedDeparture(): string
    {
        $h = intdiv($this->departureSeconds, 3600) % 24;
        $m = intdiv($this->departureSeconds % 3600, 60);
        return sprintf('%02d:%02d', $h, $m);
    }
}
