<?php

namespace App\Service\Gtfs;

use App\Entity\Gtfs\CalendarDate;
use App\Repository\Gtfs\CalendarDateRepository;
use App\Repository\Gtfs\CalendarRepository;

/**
 * For a given calendar date, returns the GTFS service_ids that are running,
 * after applying calendar.txt baseline + calendar_dates.txt exceptions.
 */
final class ActiveServicesResolver
{
    public function __construct(
        private readonly CalendarRepository $calendars,
        private readonly CalendarDateRepository $calendarDates,
    ) {
    }

    /** @return string[] */
    public function resolveForDate(\DateTimeImmutable $date): array
    {
        $services = [];

        foreach ($this->calendars->findAll() as $cal) {
            if ($cal->isRunningOn($date)) {
                $services[$cal->getServiceId()] = true;
            }
        }

        foreach ($this->calendarDates->findForDate($date) as $cd) {
            if ($cd->getExceptionType() === CalendarDate::EXCEPTION_ADDED) {
                $services[$cd->getServiceId()] = true;
            } elseif ($cd->getExceptionType() === CalendarDate::EXCEPTION_REMOVED) {
                unset($services[$cd->getServiceId()]);
            }
        }

        return array_keys($services);
    }
}
