<?php

declare(strict_types=1);

/**
 * Smoke test: parse a real GTFS-RT payload from STAS and print a summary.
 *
 * Run:
 *   curl -o /tmp/stas-rt.pb https://api.saint-etienne-metropole.fr/stas/api/horraires_tc/GTFS-RT.pb
 *   php tests/Service/Gtfs/Realtime/GtfsRtParserSmokeTest.php /tmp/stas-rt.pb
 *
 * This is intentionally a CLI script (not PHPUnit) — same style as the existing
 * StopAreaBuilderTest in this repo.
 */

require_once __DIR__ . '/../../../../src/Service/Gtfs/Realtime/ProtobufReader.php';
require_once __DIR__ . '/../../../../src/Service/Gtfs/Realtime/TripStopUpdateRow.php';
require_once __DIR__ . '/../../../../src/Service/Gtfs/Realtime/GtfsRtParser.php';

use App\Service\Gtfs\Realtime\GtfsRtParser;

$path = $argv[1] ?? '/tmp/stas-rt.pb';
if (!is_file($path)) {
    fwrite(STDERR, "Missing payload at $path\n");
    exit(2);
}

$payload = file_get_contents($path);
fwrite(STDOUT, sprintf("Loaded %d bytes from %s\n", strlen($payload), $path));

$parser = new GtfsRtParser();
$t0 = microtime(true);
$rows = $parser->parse($payload);
$dt = (microtime(true) - $t0) * 1000;

$canceledTrips = 0;
$delays = [];
$tripsSeen = [];
$stopsSeen = [];
foreach ($rows as $r) {
    if ($r->tripCanceled) {
        $canceledTrips++;
        continue;
    }
    if ($r->tripId !== null) {
        $tripsSeen[$r->tripId] = true;
    }
    if ($r->stopId !== null) {
        $stopsSeen[$r->stopId] = true;
    }
    $delays[] = $r->delaySeconds;
}

sort($delays);
$median = $delays === [] ? 0 : $delays[(int) floor(count($delays) / 2)];

printf(
    "Parsed in %.1f ms\n  rows: %d\n  canceled trips: %d\n  distinct trips: %d\n  distinct stops: %d\n  min delay: %d s\n  max delay: %d s\n  median delay: %d s\n",
    $dt,
    count($rows),
    $canceledTrips,
    count($tripsSeen),
    count($stopsSeen),
    $delays ? min($delays) : 0,
    $delays ? max($delays) : 0,
    $median,
);

echo "\nFirst 5 rows:\n";
foreach (array_slice($rows, 0, 5) as $r) {
    printf(
        "  trip=%s stop=%s seq=%s delay=%ds rel=%d %s\n",
        $r->tripId ?? '—',
        $r->stopId ?? '—',
        $r->stopSequence !== null ? (string) $r->stopSequence : '—',
        $r->delaySeconds,
        $r->scheduleRelationship,
        $r->tripCanceled ? '[CANCELED]' : '',
    );
}
