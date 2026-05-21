<?php

namespace App\Entity\Gtfs;

use App\Repository\Gtfs\CalendarRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CalendarRepository::class)]
#[ORM\Table(name: 'gtfs_calendar')]
class Calendar
{
    #[ORM\Id]
    #[ORM\Column(length: 64, name: 'service_id')]
    private string $serviceId;

    #[ORM\Column(type: 'boolean')] private bool $monday = false;
    #[ORM\Column(type: 'boolean')] private bool $tuesday = false;
    #[ORM\Column(type: 'boolean')] private bool $wednesday = false;
    #[ORM\Column(type: 'boolean')] private bool $thursday = false;
    #[ORM\Column(type: 'boolean')] private bool $friday = false;
    #[ORM\Column(type: 'boolean')] private bool $saturday = false;
    #[ORM\Column(type: 'boolean')] private bool $sunday = false;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    public function __construct(string $serviceId)
    {
        $this->serviceId = $serviceId;
    }

    public function getServiceId(): string { return $this->serviceId; }
    public function setDay(int $weekday, bool $running): self
    {
        match ($weekday) {
            1 => $this->monday = $running,
            2 => $this->tuesday = $running,
            3 => $this->wednesday = $running,
            4 => $this->thursday = $running,
            5 => $this->friday = $running,
            6 => $this->saturday = $running,
            7, 0 => $this->sunday = $running,
        };
        return $this;
    }
    public function isRunningOn(\DateTimeInterface $date): bool
    {
        $d = $date->format('Y-m-d');
        if ($d < $this->startDate->format('Y-m-d') || $d > $this->endDate->format('Y-m-d')) return false;
        return match ((int) $date->format('N')) {
            1 => $this->monday, 2 => $this->tuesday, 3 => $this->wednesday,
            4 => $this->thursday, 5 => $this->friday, 6 => $this->saturday,
            7 => $this->sunday, default => false,
        };
    }
    public function setStartDate(\DateTimeImmutable $startDate): self { $this->startDate = $startDate; return $this; }
    public function setEndDate(\DateTimeImmutable $endDate): self { $this->endDate = $endDate; return $this; }
    public function getStartDate(): \DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): \DateTimeImmutable { return $this->endDate; }
}
