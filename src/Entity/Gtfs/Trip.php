<?php

namespace App\Entity\Gtfs;

use App\Repository\Gtfs\TripRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TripRepository::class)]
#[ORM\Table(name: 'gtfs_trip')]
#[ORM\Index(name: 'idx_trip_service', columns: ['service_id'])]
#[ORM\Index(name: 'idx_trip_route', columns: ['route_id'])]
class Trip
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Route::class)]
    #[ORM\JoinColumn(name: 'route_id', referencedColumnName: 'id', nullable: false)]
    private Route $route;

    #[ORM\Column(length: 64, name: 'service_id')]
    private string $serviceId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $headsign = null;

    #[ORM\Column(nullable: true)]
    private ?int $directionId = null;

    /** @var Collection<int, StopTime> */
    #[ORM\OneToMany(targetEntity: StopTime::class, mappedBy: 'trip', cascade: ['persist'])]
    #[ORM\OrderBy(['stopSequence' => 'ASC'])]
    private Collection $stopTimes;

    public function __construct(string $id, Route $route, string $serviceId)
    {
        $this->id = $id;
        $this->route = $route;
        $this->serviceId = $serviceId;
        $this->stopTimes = new ArrayCollection();
    }

    public function getId(): string { return $this->id; }
    public function getRoute(): Route { return $this->route; }
    public function getServiceId(): string { return $this->serviceId; }
    public function getHeadsign(): ?string { return $this->headsign; }
    public function setHeadsign(?string $headsign): self { $this->headsign = $headsign; return $this; }
    public function getDirectionId(): ?int { return $this->directionId; }
    public function setDirectionId(?int $directionId): self { $this->directionId = $directionId; return $this; }
    public function getStopTimes(): Collection { return $this->stopTimes; }
}
