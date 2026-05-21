<?php

namespace App\Entity\Gtfs;

use App\Repository\Gtfs\StopAreaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StopAreaRepository::class)]
#[ORM\Table(name: 'gtfs_stop_area')]
#[ORM\Index(name: 'idx_stop_area_name', columns: ['name'])]
#[ORM\Index(name: 'idx_stop_area_coords', columns: ['latitude', 'longitude'])]
class StopArea
{
    #[ORM\Id]
    #[ORM\Column(length: 80)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    private string $latitude;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    private string $longitude;

    #[ORM\Column]
    private int $boundingRadius = 0;

    /** @var Collection<int, Stop> */
    #[ORM\OneToMany(targetEntity: Stop::class, mappedBy: 'area')]
    private Collection $stops;

    public function __construct(string $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
        $this->stops = new ArrayCollection();
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getLatitude(): float { return (float) $this->latitude; }
    public function setLatitude(float $latitude): self { $this->latitude = (string) $latitude; return $this; }
    public function getLongitude(): float { return (float) $this->longitude; }
    public function setLongitude(float $longitude): self { $this->longitude = (string) $longitude; return $this; }
    public function getBoundingRadius(): int { return $this->boundingRadius; }
    public function setBoundingRadius(int $boundingRadius): self { $this->boundingRadius = $boundingRadius; return $this; }
    public function getStops(): Collection { return $this->stops; }
}
