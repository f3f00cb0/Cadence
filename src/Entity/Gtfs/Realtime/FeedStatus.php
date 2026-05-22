<?php

declare(strict_types=1);

namespace App\Entity\Gtfs\Realtime;

use App\Repository\Gtfs\Realtime\FeedStatusRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton row tracking the freshness of the GTFS-RT feed.
 *
 * Used by the front to decide whether to advertise "TEMPS RÉEL" honestly and
 * to display a last-sync indicator. Always one row, primary key = "stas".
 */
#[ORM\Entity(repositoryClass: FeedStatusRepository::class)]
#[ORM\Table(name: 'gtfs_rt_feed_status')]
class FeedStatus
{
    public const ID = 'stas';

    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private string $id;

    /** Timestamp of the last successful fetch + parse. Null if never succeeded. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    /** Timestamp of the last attempt (success or failure). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    /** Number of trip-stop rows ingested at the last successful fetch. */
    #[ORM\Column]
    private int $lastRowCount = 0;

    /** Consecutive failures since the last success. */
    #[ORM\Column]
    private int $consecutiveFailures = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastError = null;

    public function __construct(string $id = self::ID)
    {
        $this->id = $id;
    }

    public function getId(): string { return $this->id; }
    public function getLastSuccessAt(): ?\DateTimeImmutable { return $this->lastSuccessAt; }
    public function getLastAttemptAt(): ?\DateTimeImmutable { return $this->lastAttemptAt; }
    public function getLastRowCount(): int { return $this->lastRowCount; }
    public function getConsecutiveFailures(): int { return $this->consecutiveFailures; }
    public function getLastError(): ?string { return $this->lastError; }

    public function recordSuccess(\DateTimeImmutable $at, int $rowCount): void
    {
        $this->lastAttemptAt = $at;
        $this->lastSuccessAt = $at;
        $this->lastRowCount = $rowCount;
        $this->consecutiveFailures = 0;
        $this->lastError = null;
    }

    public function recordFailure(\DateTimeImmutable $at, string $error): void
    {
        $this->lastAttemptAt = $at;
        $this->consecutiveFailures++;
        $this->lastError = mb_substr($error, 0, 255);
    }
}
