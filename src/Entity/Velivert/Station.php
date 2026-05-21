<?php

namespace App\Entity\Velivert;

use App\Repository\Velivert\StationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vélivert station — combines GBFS station_information + station_status feeds.
 */
#[ORM\Entity(repositoryClass: StationRepository::class)]
#[ORM\Table(name: 'velivert_station')]
class Station
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    private string $latitude;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    private string $longitude;

    #[ORM\Column]
    private int $capacity = 0;

    #[ORM\Column]
    private int $bikesAvailable = 0;

    #[ORM\Column]
    private int $docksAvailable = 0;

    #[ORM\Column]
    private bool $isInstalled = true;

    #[ORM\Column]
    private bool $isRenting = true;

    #[ORM\Column]
    private bool $isReturning = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastReportedAt = null;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): self { $this->address = $address; return $this; }
    public function getLatitude(): float { return (float) $this->latitude; }
    public function setLatitude(float $latitude): self { $this->latitude = (string) $latitude; return $this; }
    public function getLongitude(): float { return (float) $this->longitude; }
    public function setLongitude(float $longitude): self { $this->longitude = (string) $longitude; return $this; }
    public function getCapacity(): int { return $this->capacity; }
    public function setCapacity(int $capacity): self { $this->capacity = $capacity; return $this; }
    public function getBikesAvailable(): int { return $this->bikesAvailable; }
    public function setBikesAvailable(int $bikesAvailable): self { $this->bikesAvailable = $bikesAvailable; return $this; }
    public function getDocksAvailable(): int { return $this->docksAvailable; }
    public function setDocksAvailable(int $docksAvailable): self { $this->docksAvailable = $docksAvailable; return $this; }
    public function isInstalled(): bool { return $this->isInstalled; }
    public function setIsInstalled(bool $isInstalled): self { $this->isInstalled = $isInstalled; return $this; }
    public function isRenting(): bool { return $this->isRenting; }
    public function setIsRenting(bool $isRenting): self { $this->isRenting = $isRenting; return $this; }
    public function isReturning(): bool { return $this->isReturning; }
    public function setIsReturning(bool $isReturning): self { $this->isReturning = $isReturning; return $this; }
    public function getLastReportedAt(): ?\DateTimeImmutable { return $this->lastReportedAt; }
    public function setLastReportedAt(?\DateTimeImmutable $lastReportedAt): self { $this->lastReportedAt = $lastReportedAt; return $this; }
}
