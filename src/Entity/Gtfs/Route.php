<?php

namespace App\Entity\Gtfs;

use App\Repository\Gtfs\RouteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RouteRepository::class)]
#[ORM\Table(name: 'gtfs_route')]
class Route
{
    public const TYPE_TRAM = 0;
    public const TYPE_SUBWAY = 1;
    public const TYPE_RAIL = 2;
    public const TYPE_BUS = 3;
    public const TYPE_TROLLEYBUS = 11;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $shortName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $longName = null;

    #[ORM\Column]
    private int $type = self::TYPE_BUS;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $textColor = null;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string { return $this->id; }
    public function getShortName(): ?string { return $this->shortName; }
    public function setShortName(?string $shortName): self { $this->shortName = $shortName; return $this; }
    public function getLongName(): ?string { return $this->longName; }
    public function setLongName(?string $longName): self { $this->longName = $longName; return $this; }
    public function getType(): int { return $this->type; }
    public function setType(int $type): self { $this->type = $type; return $this; }
    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }
    public function getTextColor(): ?string { return $this->textColor; }
    public function setTextColor(?string $textColor): self { $this->textColor = $textColor; return $this; }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_TRAM => 'tram',
            self::TYPE_SUBWAY => 'metro',
            self::TYPE_RAIL => 'train',
            self::TYPE_TROLLEYBUS => 'trolley',
            default => 'bus',
        };
    }
}
