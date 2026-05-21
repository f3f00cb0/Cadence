<?php

namespace App\Entity\Gtfs;

use App\Repository\Gtfs\StopRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StopRepository::class)]
#[ORM\Table(name: 'gtfs_stop')]
#[ORM\Index(name: 'idx_stop_name', columns: ['name'])]
#[ORM\Index(name: 'idx_stop_coords', columns: ['latitude', 'longitude'])]
class Stop
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    private string $latitude;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    private string $longitude;

    #[ORM\Column(nullable: true)]
    private ?int $locationType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $parentStation = null;

    #[ORM\ManyToOne(targetEntity: StopArea::class, inversedBy: 'stops')]
    #[ORM\JoinColumn(name: 'area_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?StopArea $area = null;

    /** @var Collection<int, StopTime> */
    #[ORM\OneToMany(targetEntity: StopTime::class, mappedBy: 'stop')]
    private Collection $stopTimes;

    public function __construct(string $id)
    {
        $this->id = $id;
        $this->stopTimes = new ArrayCollection();
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getLatitude(): float { return (float) $this->latitude; }
    public function setLatitude(float $latitude): self { $this->latitude = (string) $latitude; return $this; }
    public function getLongitude(): float { return (float) $this->longitude; }
    public function setLongitude(float $longitude): self { $this->longitude = (string) $longitude; return $this; }
    public function getLocationType(): ?int { return $this->locationType; }
    public function setLocationType(?int $locationType): self { $this->locationType = $locationType; return $this; }
    public function getParentStation(): ?string { return $this->parentStation; }
    public function setParentStation(?string $parentStation): self { $this->parentStation = $parentStation; return $this; }
    public function getArea(): ?StopArea { return $this->area; }
    public function setArea(?StopArea $area): self { $this->area = $area; return $this; }
    public function getStopTimes(): Collection { return $this->stopTimes; }
}
