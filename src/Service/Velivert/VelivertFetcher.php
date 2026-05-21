<?php

namespace App\Service\Velivert;

use App\Entity\Velivert\Station;
use App\Repository\Velivert\StationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches GBFS feeds for Vélivert and upserts station information + status
 * into the database. The GBFS discovery file points to the actual feed URLs.
 *
 * Two relevant feeds:
 *  - station_information : static-ish (name, lat/lon, capacity)
 *  - station_status      : real-time (bikes/docks available, operational state)
 *
 * @see https://github.com/MobilityData/gbfs
 */
final class VelivertFetcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly StationRepository $stations,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter] private readonly string $gbfsUrl,
    ) {
    }

    public function refresh(): array
    {
        $feeds = $this->discoverFeeds();

        $info = $this->fetchJson($feeds['station_information']);
        $status = $this->fetchJson($feeds['station_status']);

        $infoMap = [];
        foreach ($info['data']['stations'] as $s) {
            $infoMap[$s['station_id']] = $s;
        }

        $statusMap = [];
        foreach ($status['data']['stations'] as $s) {
            $statusMap[$s['station_id']] = $s;
        }

        $count = 0;
        foreach ($infoMap as $id => $iRow) {
            $station = $this->stations->find($id) ?? new Station($id);
            $station->setName($iRow['name'] ?? '—');
            $station->setAddress($iRow['address'] ?? null);
            $station->setLatitude((float) ($iRow['lat'] ?? 0));
            $station->setLongitude((float) ($iRow['lon'] ?? 0));
            $station->setCapacity((int) ($iRow['capacity'] ?? 0));

            if (isset($statusMap[$id])) {
                $st = $statusMap[$id];
                $station->setBikesAvailable((int) ($st['num_bikes_available'] ?? 0));
                $station->setDocksAvailable((int) ($st['num_docks_available'] ?? 0));
                $station->setIsInstalled((bool) ($st['is_installed'] ?? true));
                $station->setIsRenting((bool) ($st['is_renting'] ?? true));
                $station->setIsReturning((bool) ($st['is_returning'] ?? true));

                if (isset($st['last_reported'])) {
                    $station->setLastReportedAt(
                        new \DateTimeImmutable('@' . (int) $st['last_reported'])
                    );
                }
            }

            $this->em->persist($station);
            $count++;
        }

        $this->em->flush();
        $this->logger->info('Vélivert refreshed', ['stations' => $count]);

        return ['stations_upserted' => $count];
    }

    /** @return array{station_information:string, station_status:string} */
    private function discoverFeeds(): array
    {
        $discovery = $this->fetchJson($this->gbfsUrl);

        // GBFS discovery shape: data.{lang}.feeds[]  OR  data.feeds[] depending on version.
        $feedsList = null;
        if (isset($discovery['data']['feeds'])) {
            $feedsList = $discovery['data']['feeds'];
        } else {
            foreach ($discovery['data'] ?? [] as $lang => $payload) {
                if (isset($payload['feeds'])) {
                    $feedsList = $payload['feeds'];
                    break;
                }
            }
        }

        if (!$feedsList) {
            throw new \RuntimeException('Cannot find feeds list in GBFS discovery');
        }

        $byName = [];
        foreach ($feedsList as $f) {
            $byName[$f['name']] = $f['url'];
        }

        if (!isset($byName['station_information'], $byName['station_status'])) {
            throw new \RuntimeException('Missing station_information or station_status feeds');
        }

        return [
            'station_information' => $byName['station_information'],
            'station_status' => $byName['station_status'],
        ];
    }

    private function fetchJson(string $url): array
    {
        $resp = $this->httpClient->request('GET', $url, ['timeout' => 30]);
        return $resp->toArray(false);
    }
}
