<?php

namespace App\Repository\Gtfs;

use App\Entity\Gtfs\CalendarDate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CalendarDate> */
class CalendarDateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarDate::class);
    }

    /** @return CalendarDate[] */
    public function findForDate(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('cd')
            ->where('cd.date = :d')
            ->setParameter('d', $date->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }
}
