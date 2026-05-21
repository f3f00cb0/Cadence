<?php

namespace App\Scheduler;

use App\Message\RefreshGtfsMessage;
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
                RecurringMessage::every('60 seconds', new RefreshVelivertMessage()),
                RecurringMessage::cron(
                    '17 4 * * 1',
                    new RefreshGtfsMessage(),
                    new \DateTimeZone('Europe/Paris'),
                ),
            );
    }
}
