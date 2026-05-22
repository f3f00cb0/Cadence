<?php

declare(strict_types=1);

namespace App\Repository\Gtfs\Realtime;

use App\Entity\Gtfs\Realtime\FeedStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeedStatus>
 */
class FeedStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedStatus::class);
    }

    public function getOrCreate(string $id = FeedStatus::ID): FeedStatus
    {
        $status = $this->find($id);
        if ($status === null) {
            $status = new FeedStatus($id);
            $this->getEntityManager()->persist($status);
        }
        return $status;
    }
}
