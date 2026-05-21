<?php

namespace App\MessageHandler;

use App\Message\RefreshVelivertMessage;
use App\Service\Velivert\VelivertFetcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshVelivertHandler
{
    public function __construct(private readonly VelivertFetcher $fetcher)
    {
    }

    public function __invoke(RefreshVelivertMessage $message): void
    {
        $this->fetcher->refresh();
    }
}
