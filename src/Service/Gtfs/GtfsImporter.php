<?php

namespace App\Service\Gtfs;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports a GTFS zip into the database using raw DBAL for speed.
 * STAS has ~2000 stops, ~80 routes, but hundreds of thousands of stop_times.
 */
final class GtfsImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter] private readonly string $gtfsUrl,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function importFromUrl(?string $url = null): array
    {
        $url ??= $this->gtfsUrl;
        $this->logger->info('GTFS download starting', ['url' => $url]);

        $tmpZip = tempnam(sys_get_temp_dir(), 'gtfs_') . '.zip';
        $client = $this->httpClient ?? HttpClient::create(['timeout' => 120]);
        $response = $client->request('GET', $url);
        file_put_contents($tmpZip, $response->getContent());

        $extractDir = sys_get_temp_dir() . '/gtfs_' . uniqid('', true);
        mkdir($extractDir, 0755, true);
        $zip = new \ZipArchive();
        if (true !== $zip->open($tmpZip)) {
            throw new \RuntimeException("Cannot open GTFS zip at {$tmpZip}");
        }
        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($tmpZip);

        try {
            return $this->importFromDirectory($extractDir);
        } finally {
            $this->cleanDir($extractDir);
        }
    }

    public function importFromDirectory(string $dir): array
    {
        $stats = [];
        $this->db->executeStatement('SET session_replication_role = replica');

        try {
            $this->truncate(['gtfs_stop_time', 'gtfs_trip', 'gtfs_route', 'gtfs_stop', 'gtfs_calendar', 'gtfs_calendar_date']);

            $stats['stops']           = $this->importStops("$dir/stops.txt");
            $stats['routes']          = $this->importRoutes("$dir/routes.txt");
            $stats['calendar']        = $this->importCalendar("$dir/calendar.txt");
            $stats['calendar_dates']  = $this->importCalendarDates("$dir/calendar_dates.txt");
            $stats['trips']           = $this->importTrips("$dir/trips.txt");
            $stats['stop_times']      = $this->importStopTimes("$dir/stop_times.txt");
        } finally {
            $this->db->executeStatement('SET session_replication_role = origin');
        }

        $this->logger->info('GTFS import done', $stats);
        return $stats;
    }

    private function importStops(string $path): int
    {
        return $this->bulkCsv($path, function (array $row): array {
            return [
                'sql' => 'INSERT INTO gtfs_stop (id, name, latitude, longitude, location_type, parent_station) VALUES (?, ?, ?, ?, ?, ?)',
                'params' => [
                    $row['stop_id'],
                    $row['stop_name'] ?? '',
                    $row['stop_lat'] ?: '0',
                    $row['stop_lon'] ?: '0',
                    isset($row['location_type']) && $row['location_type'] !== '' ? (int) $row['location_type'] : null,
                    $row['parent_station'] ?? null,
                ],
            ];
        });
    }

    private function importRoutes(string $path): int
    {
        return $this->bulkCsv($path, function (array $row): array {
            return [
                'sql' => 'INSERT INTO gtfs_route (id, short_name, long_name, type, color, text_color) VALUES (?, ?, ?, ?, ?, ?)',
                'params' => [
                    $row['route_id'],
                    $row['route_short_name'] ?? null,
                    $row['route_long_name'] ?? null,
                    (int) ($row['route_type'] ?? 3),
                    isset($row['route_color']) && $row['route_color'] !== '' ? '#' . ltrim($row['route_color'], '#') : null,
                    isset($row['route_text_color']) && $row['route_text_color'] !== '' ? '#' . ltrim($row['route_text_color'], '#') : null,
                ],
            ];
        });
    }

    private function importCalendar(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        return $this->bulkCsv($path, function (array $row): array {
            return [
                'sql' => 'INSERT INTO gtfs_calendar (service_id, monday, tuesday, wednesday, thursday, friday, saturday, sunday, start_date, end_date)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'params' => [
                    $row['service_id'],
                    (int) ($row['monday'] ?? 0),
                    (int) ($row['tuesday'] ?? 0),
                    (int) ($row['wednesday'] ?? 0),
                    (int) ($row['thursday'] ?? 0),
                    (int) ($row['friday'] ?? 0),
                    (int) ($row['saturday'] ?? 0),
                    (int) ($row['sunday'] ?? 0),
                    self::ymd($row['start_date']),
                    self::ymd($row['end_date']),
                ],
            ];
        });
    }

    private function importCalendarDates(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        return $this->bulkCsv($path, function (array $row): array {
            return [
                'sql' => 'INSERT INTO gtfs_calendar_date (service_id, date, exception_type) VALUES (?, ?, ?)',
                'params' => [
                    $row['service_id'],
                    self::ymd($row['date']),
                    (int) $row['exception_type'],
                ],
            ];
        });
    }

    private function importTrips(string $path): int
    {
        return $this->bulkCsv($path, function (array $row): array {
            return [
                'sql' => 'INSERT INTO gtfs_trip (id, route_id, service_id, headsign, direction_id) VALUES (?, ?, ?, ?, ?)',
                'params' => [
                    $row['trip_id'],
                    $row['route_id'],
                    $row['service_id'],
                    $row['trip_headsign'] ?? null,
                    isset($row['direction_id']) && $row['direction_id'] !== '' ? (int) $row['direction_id'] : null,
                ],
            ];
        });
    }

    private function importStopTimes(string $path): int
    {
        return $this->bulkCsv($path, function (array $row): array {
            $arr = self::secs($row['arrival_time']);
            $dep = self::secs($row['departure_time']);
            return [
                'sql' => 'INSERT INTO gtfs_stop_time (trip_id, stop_id, stop_sequence, arrival_seconds, departure_seconds, stop_headsign) VALUES (?, ?, ?, ?, ?, ?)',
                'params' => [
                    $row['trip_id'],
                    $row['stop_id'],
                    (int) $row['stop_sequence'],
                    $arr,
                    $dep,
                    $row['stop_headsign'] ?? null,
                ],
            ];
        }, batchSize: 2000);
    }

    /** @param callable(array<string,string>): array{sql:string,params:array} $mapper */
    private function bulkCsv(string $path, callable $mapper, int $batchSize = 500): int
    {
        if (!is_file($path)) {
            $this->logger->warning('GTFS file missing', ['path' => $path]);
            return 0;
        }

        $fh = fopen($path, 'r');
        $headers = fgetcsv($fh);
        $headers = array_map(fn($h) => trim($h, "\xEF\xBB\xBF "), $headers); // strip BOM

        $count = 0;
        $this->db->beginTransaction();
        try {
            while (($row = fgetcsv($fh)) !== false) {
                $assoc = array_combine($headers, array_pad($row, count($headers), null));
                $stmt = $mapper($assoc);
                $this->db->executeStatement($stmt['sql'], $stmt['params']);
                if (++$count % $batchSize === 0) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        } finally {
            fclose($fh);
        }
        return $count;
    }

    private function truncate(array $tables): void
    {
        foreach ($tables as $t) {
            $this->db->executeStatement("TRUNCATE TABLE {$t} RESTART IDENTITY CASCADE");
        }
    }

    private function cleanDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    private static function ymd(string $yyyymmdd): string
    {
        return substr($yyyymmdd, 0, 4) . '-' . substr($yyyymmdd, 4, 2) . '-' . substr($yyyymmdd, 6, 2);
    }

    private static function secs(string $hhmmss): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hhmmss));
        return $h * 3600 + $m * 60 + $s;
    }
}
