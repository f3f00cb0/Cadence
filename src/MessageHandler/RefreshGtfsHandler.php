<?php

namespace App\MessageHandler;

use App\Message\RefreshGtfsMessage;
use App\Service\Gtfs\GtfsImporter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshGtfsHandler
{
    public function __construct(
        private readonly GtfsImporter $importer,
    ) {
    }

    public function __invoke(RefreshGtfsMessage $message): void
    {
        // Regrouping stops into StopAreas is part of importFromUrl(): the
        // importer owns that invariant, so this handler cannot drift from it.
        $this->importer->importFromUrl();
    }
}
