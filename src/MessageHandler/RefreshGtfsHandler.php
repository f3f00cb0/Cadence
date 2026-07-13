<?php

namespace App\MessageHandler;

use App\Message\RefreshGtfsMessage;
use App\Service\Gtfs\GtfsImporter;
use App\Service\Gtfs\StopAreaBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshGtfsHandler
{
    public function __construct(
        private readonly GtfsImporter $importer,
        private readonly StopAreaBuilder $areaBuilder,
    ) {
    }

    public function __invoke(RefreshGtfsMessage $message): void
    {
        $this->importer->importFromUrl();

        // importFromUrl() truncates+reinserts gtfs_stop, which drops every
        // Stop -> StopArea link. Without this, all departures endpoints
        // return empty until someone runs app:gtfs:group-stops by hand.
        $this->areaBuilder->rebuild();
    }
}
