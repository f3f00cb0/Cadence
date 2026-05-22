<?php

namespace App\Scheduler;

use App\Message\RefreshGtfsMessage;
use App\Message\RefreshGtfsRtMessage;
use App\Message\RefreshVelivertMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('main')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                // Vélivert GBFS (bikes/docks) — once a minute is plenty.
                RecurringMessage::every('60 seconds', new RefreshVelivertMessage()),

                // GTFS-Realtime (trip updates) — every 30 s. The STAS feed updates
                // every few seconds upstream; 30 s strikes a balance between
                // freshness and DB churn (~2-3k row TRUNCATE+INSERT each tick).
                RecurringMessage::every('30 seconds', new RefreshGtfsRtMessage()),

                // Static GTFS — Monday 04:17 Europe/Paris.
                RecurringMessage::cron(
                    '17 4 * * 1',
                    new RefreshGtfsMessage(),
                    new \DateTimeZone('Europe/Paris'),
                ),
            );
    }
}
