<?php

declare(strict_types=1);

namespace App\Repository\Gtfs\Realtime;

use App\Entity\Gtfs\Realtime\TripStopUpdate;
use App\Service\Gtfs\Realtime\TripStopUpdateRow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TripStopUpdate>
 */
class TripStopUpdateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly Connection $conn)
    {
        parent::__construct($registry, TripStopUpdate::class);
    }

    /**
     * Atomically replace all rows in gtfs_rt_trip_update with the given batch.
     * Uses TRUNCATE + bulk INSERT inside a transaction so readers never see
     * an empty table mid-refresh.
     *
     * @param TripStopUpdateRow[] $rows
     */
    public function replaceAll(array $rows): int
    {
        $this->conn->beginTransaction();
        try {
            $this->conn->executeStatement('TRUNCATE TABLE gtfs_rt_trip_update');

            if ($rows === []) {
                $this->conn->commit();
                return 0;
            }

            $sql = 'INSERT INTO gtfs_rt_trip_update '
                . '(trip_id, route_id, start_date, stop_id, stop_sequence, delay_seconds, schedule_relationship, trip_canceled) '
                . 'VALUES ';

            // Chunk to avoid massive single statements (Postgres handles huge inserts
            // but it makes errors hard to diagnose).
            foreach (array_chunk($rows, 500) as $chunk) {
                $placeholders = [];
                $params = [];
                $types = [];
                foreach ($chunk as $row) {
                    $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    $params[] = $row->tripId;
                    $params[] = $row->routeId;
                    $params[] = $row->startDate;
                    $params[] = $row->stopId;
                    $params[] = $row->stopSequence;
                    $params[] = $row->delaySeconds;
                    $params[] = $row->scheduleRelationship;
                    $params[] = $row->tripCanceled ? 'true' : 'false';
                    $types = []; // let DBAL infer
                }
                $this->conn->executeStatement($sql . implode(',', $placeholders), $params);
            }

            $this->conn->commit();
            return count($rows);
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Fetch all RT patches for a set of trip IDs in one query. Returned shape
     * is optimized for the merger:
     *
     *   [
     *     "$tripId|$startDate" => [
     *        'canceled' => bool,
     *        'byStop'   => ['stopId' => ['delay' => int, 'rel' => int], ...],
     *        'bySeq'    => [sequence => ['delay' => int, 'rel' => int], ...],
     *     ],
     *     ...
     *   ]
     *
     * @param string[] $tripIds
     * @return array<string, array{canceled:bool, byStop:array<string,array{delay:int,rel:int}>, bySeq:array<int,array{delay:int,rel:int}>}>
     */
    public function indexByTrip(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT trip_id, start_date, stop_id, stop_sequence, delay_seconds, schedule_relationship, trip_canceled
             FROM gtfs_rt_trip_update
             WHERE trip_id IN (?)',
            [array_values(array_unique($tripIds))],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $out = [];
        foreach ($rows as $r) {
            $key = ($r['trip_id'] ?? '') . '|' . ($r['start_date'] ?? '');
            if (!isset($out[$key])) {
                $out[$key] = ['canceled' => false, 'byStop' => [], 'bySeq' => []];
            }
            if ($r['trip_canceled']) {
                $out[$key]['canceled'] = true;
                continue;
            }
            $entry = [
                'delay' => (int) $r['delay_seconds'],
                'rel' => (int) $r['schedule_relationship'],
            ];
            if ($r['stop_id'] !== null) {
                $out[$key]['byStop'][$r['stop_id']] = $entry;
            }
            if ($r['stop_sequence'] !== null) {
                $out[$key]['bySeq'][(int) $r['stop_sequence']] = $entry;
            }
        }
        return $out;
    }
}
