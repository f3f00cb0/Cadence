<?php

namespace App\Service\Gtfs;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GtfsRtImporter
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter] private readonly string $gtfsRtUrl,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function fetch(?string $url = null): string
    {
        $url ??= $this->gtfsRtUrl;
        $this->logger->info('GTFS-RT download starting', ['url' => $url]);

        $client = $this->httpClient ?? HttpClient::create(['timeout' => 30]);
        $response = $client->request('GET', $url);

        return $response->getContent();
    }
}
