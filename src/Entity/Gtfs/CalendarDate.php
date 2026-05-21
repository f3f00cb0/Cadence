<?php

namespace App\Entity\Gtfs;

use App\Repository\Gtfs\CalendarDateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CalendarDateRepository::class)]
#[ORM\Table(name: 'gtfs_calendar_date')]
#[ORM\Index(name: 'idx_calendar_date', columns: ['date'])]
class CalendarDate
{
    public const EXCEPTION_ADDED = 1;
    public const EXCEPTION_REMOVED = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $serviceId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private int $exceptionType;

    public function __construct(string $serviceId, \DateTimeImmutable $date, int $exceptionType)
    {
        $this->serviceId = $serviceId;
        $this->date = $date;
        $this->exceptionType = $exceptionType;
    }
    public function getServiceId(): string { return $this->serviceId; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getExceptionType(): int { return $this->exceptionType; }
}
