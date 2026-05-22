<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshGtfsRtMessage;
use App\Service\Gtfs\GtfsRtImporter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshGtfsRtHandler
{
    public function __construct(private readonly GtfsRtImporter $importer)
    {
    }

    public function __invoke(RefreshGtfsRtMessage $message): void
    {
        // Swallow exceptions here so a single transient failure doesn't kill the
        // scheduler loop — GtfsRtImporter already logs + bumps FeedStatus.
        try {
            $this->importer->refresh();
        } catch (\Throwable) {
            // Already logged inside refresh(); next tick will retry.
        }
    }
}
