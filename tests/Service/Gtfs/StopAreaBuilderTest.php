<?php

declare(strict_types=1);

/**
 * Standalone test for StopAreaBuilder pure logic (normalization + clustering).
 *
 * Run with: `php tests/Service/Gtfs/StopAreaBuilderTest.php`
 * Returns exit code 0 on success, 1 on failure. Self-contained — no PHPUnit
 * needed because the project doesn't ship a test framework yet.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use App\Entity\Gtfs\Stop;
use App\Service\Gtfs\StopAreaBuilder;

$failures = [];

function check(string $label, mixed $expected, mixed $actual, array &$failures): void
{
    $ok = $expected === $actual;
    $icon = $ok ? 'OK ' : 'KO ';
    echo "  [{$icon}] {$label}\n";
    if (!$ok) {
        $failures[] = sprintf(
            "%s — expected %s, got %s",
            $label,
            var_export($expected, true),
            var_export($actual, true),
        );
    }
}

echo "== StopAreaBuilder::normalize ==\n";

check('strips "direction nord" suffix',
    'hotel de ville',
    StopAreaBuilder::normalize('Hôtel de Ville direction nord'),
    $failures,
);
check('strips "Sud" abbreviated',
    'place du peuple',
    StopAreaBuilder::normalize('Place du Peuple Sud'),
    $failures,
);
check('strips "vers N"',
    'gare',
    StopAreaBuilder::normalize('Gare vers N'),
    $failures,
);
check('strips trailing quay letter',
    'charpennes',
    StopAreaBuilder::normalize('Charpennes B'),
    $failures,
);
check('strips "- quai 3"',
    'chateaucreux',
    StopAreaBuilder::normalize('Châteaucreux - quai 3'),
    $failures,
);
check('keeps plain name',
    'republique',
    StopAreaBuilder::normalize('République'),
    $failures,
);
check('joins multi-word name',
    'place jean jaures',
    StopAreaBuilder::normalize('Place Jean Jaurès'),
    $failures,
);

echo "\n== StopAreaBuilder::clusterByProximity ==\n";

$em = new \ReflectionClass(StopAreaBuilder::class);
// Build a minimal builder; we only use clusterByProximity which is pure.
// Instantiate without invoking constructor (avoids needing real EM/DB).
$builder = $em->newInstanceWithoutConstructor();

$makeStop = static function (string $id, float $lat, float $lon): Stop {
    $s = new Stop($id);
    $s->setName($id);
    $s->setLatitude($lat);
    $s->setLongitude($lon);
    return $s;
};

// Two stops 80m apart should land in the same cluster.
$close = [
    $makeStop('a', 45.4397, 4.3872),
    $makeStop('b', 45.4404, 4.3872),  // ~78m north
];
$clusters = $builder->clusterByProximity($close);
check('two close stops cluster together', 1, \count($clusters), $failures);
check('  cluster size is 2', 2, \count($clusters[0]), $failures);

// Two stops 3km apart should split.
$far = [
    $makeStop('a', 45.4397, 4.3872),
    $makeStop('b', 45.4660, 4.3872),  // ~2.9km north
];
$clusters = $builder->clusterByProximity($far);
check('two far stops split', 2, \count($clusters), $failures);

// Mixed: 3 close + 1 far
$mixed = [
    $makeStop('a', 45.4397, 4.3872),
    $makeStop('b', 45.4399, 4.3873),
    $makeStop('c', 45.4400, 4.3870),
    $makeStop('z', 45.4660, 4.3872),
];
$clusters = $builder->clusterByProximity($mixed);
check('mixed close+far splits 2 clusters', 2, \count($clusters), $failures);

$sizes = array_map('count', $clusters);
sort($sizes);
check('cluster sizes are [1, 3]', [1, 3], $sizes, $failures);

echo "\n== StopAreaBuilder::buildAreaId ==\n";
$id1 = StopAreaBuilder::buildAreaId('hotel de ville', 45.4397, 4.3872, 0);
$id2 = StopAreaBuilder::buildAreaId('hotel de ville', 45.4397, 4.3872, 0);
check('id is deterministic', $id1, $id2, $failures);
check('id starts with slug', true, str_starts_with($id1, 'hotel-de-ville-'), $failures);

$id3 = StopAreaBuilder::buildAreaId('hotel de ville', 45.4660, 4.3872, 0);
check('different coords → different id', true, $id1 !== $id3, $failures);

echo "\n";
if ($failures) {
    echo "FAILED (" . \count($failures) . " assertion(s)):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "OK — all checks passed.\n";
exit(0);
