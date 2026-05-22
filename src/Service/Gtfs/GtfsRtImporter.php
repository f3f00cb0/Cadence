<?php

declare(strict_types=1);

namespace App\Service\Gtfs;

use App\Repository\Gtfs\Realtime\FeedStatusRepository;
use App\Repository\Gtfs\Realtime\TripStopUpdateRepository;
use App\Service\Gtfs\Realtime\GtfsRtParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches the STAS GTFS-Realtime feed (protobuf), parses it, and replaces the
 * full set of trip-stop patches in Postgres. Also updates the FeedStatus
 * singleton so the front can advertise an honest "TEMPS RÉEL" indicator.
 *
 * Wire reference: see GtfsRtParser docblock.
 */
final class GtfsRtImporter
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly GtfsRtParser $parser,
        private readonly TripStopUpdateRepository $updates,
        private readonly FeedStatusRepository $feedStatus,
        private readonly EntityManagerInterface $em,
        #[\SensitiveParameter] private readonly string $gtfsRtUrl,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * Fetch + parse + persist in one call.
     *
     * @return array{rows:int,canceled_trips:int,duration_ms:int}
     */
    public function refresh(?string $url = null): array
    {
        $url ??= $this->gtfsRtUrl;
        $now = new \DateTimeImmutable();
        $status = $this->feedStatus->getOrCreate();
        $t0 = microtime(true);

        try {
            $payload = $this->fetch($url);
            $rows = $this->parser->parse($payload);
            $written = $this->updates->replaceAll($rows);

            $canceled = 0;
            foreach ($rows as $r) {
                if ($r->tripCanceled) {
                    $canceled++;
                }
            }

            $status->recordSuccess($now, $written);
            $this->em->flush();

            $dt = (int) round((microtime(true) - $t0) * 1000);
            $this->logger->info('GTFS-RT refresh OK', [
                'rows' => $written,
                'canceled' => $canceled,
                'duration_ms' => $dt,
            ]);

            return ['rows' => $written, 'canceled_trips' => $canceled, 'duration_ms' => $dt];
        } catch (\Throwable $e) {
            $status->recordFailure($now, $e->getMessage());
            // Best-effort flush — if even this fails, we already logged.
            try { $this->em->flush(); } catch (\Throwable) {}

            $this->logger->error('GTFS-RT refresh failed', [
                'error' => $e->getMessage(),
                'consecutive_failures' => $status->getConsecutiveFailures(),
            ]);

            throw $e;
        }
    }

    /**
     * Raw fetch — exposed for debugging / manual testing. Not used by refresh().
     */
    public function fetch(?string $url = null): string
    {
        $url ??= $this->gtfsRtUrl;
        $client = $this->httpClient ?? HttpClient::create(['timeout' => 30]);
        $response = $client->request('GET', $url);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("GTFS-RT fetch returned HTTP $status");
        }

        return $response->getContent();
    }
}
