<?php

namespace App\Service\Gtfs;

use App\Entity\Gtfs\Stop;
use App\Entity\Gtfs\StopArea;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Groups GTFS Stops into StopAreas using name normalization + geo clustering.
 *
 * Rationale: French GTFS feeds rarely populate `parent_station`. Quays of the
 * same physical place (e.g. "Hôtel de Ville direction nord/sud") show up as
 * separate Stops. We rebuild a clean StopArea grouping ourselves.
 */
final class StopAreaBuilder
{
    /** Same-name stops further than this from the sub-group centroid spawn a new area. */
    public const CLUSTER_RADIUS_METERS = 250;

    private SluggerInterface $slugger;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
        $this->slugger = new AsciiSlugger('fr');
    }

    /**
     * @return array{areas: int, stops: int, orphans: int}
     */
    public function rebuild(): array
    {
        $this->truncate();

        /** @var Stop[] $stops */
        $stops = $this->em->getRepository(Stop::class)->findAll();

        $byNormalized = [];
        foreach ($stops as $stop) {
            $norm = self::normalize($stop->getName());
            $byNormalized[$norm] ??= ['display' => self::displayName($stop->getName()), 'stops' => []];
            $byNormalized[$norm]['stops'][] = $stop;
        }

        $areasCreated = 0;
        $stopsAttached = 0;

        foreach ($byNormalized as $norm => $group) {
            $clusters = $this->clusterByProximity($group['stops']);
            foreach ($clusters as $idx => $cluster) {
                [$centroidLat, $centroidLon] = self::centroid($cluster);
                $radius = self::radius($cluster, $centroidLat, $centroidLon);

                $id = self::buildAreaId($norm, $centroidLat, $centroidLon, $idx);
                $area = new StopArea($id, $group['display']);
                $area->setLatitude($centroidLat);
                $area->setLongitude($centroidLon);
                $area->setBoundingRadius($radius);

                $this->em->persist($area);
                foreach ($cluster as $stop) {
                    $stop->setArea($area);
                    $stopsAttached++;
                }
                $areasCreated++;
            }
        }

        $this->em->flush();
        $this->em->clear();

        $this->denormalizeModesAndRoutes();

        $orphans = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM gtfs_stop WHERE area_id IS NULL AND (location_type IS NULL OR location_type = 0)'
        );

        return ['areas' => $areasCreated, 'stops' => $stopsAttached, 'orphans' => $orphans];
    }

    /**
     * Populates each area's `modes` (ordered list of mode labels) and `routes`
     * (ordered list of route metadata) columns from the GTFS join graph.
     * Kept in lockstep with the backfill in migration Version20260522150000.
     */
    private function denormalizeModesAndRoutes(): void
    {
        $this->db->executeStatement(<<<'SQL'
            WITH area_route_pairs AS (
                SELECT DISTINCT s.area_id, t.route_id
                FROM gtfs_stop_time st
                JOIN gtfs_stop  s ON s.id = st.stop_id
                JOIN gtfs_trip  t ON t.id = st.trip_id
                WHERE s.area_id IS NOT NULL
            ),
            area_routes AS (
                SELECT
                    arp.area_id,
                    r.short_name,
                    r.color,
                    r.text_color,
                    CASE r.type
                        WHEN 0  THEN 'tram'
                        WHEN 1  THEN 'metro'
                        WHEN 2  THEN 'train'
                        WHEN 11 THEN 'trolley'
                        ELSE         'bus'
                    END AS mode_label,
                    CASE r.type
                        WHEN 0  THEN 0
                        WHEN 1  THEN 1
                        WHEN 11 THEN 2
                        ELSE         3
                    END AS mode_rank
                FROM area_route_pairs arp
                JOIN gtfs_route r ON r.id = arp.route_id
            ),
            routes_agg AS (
                SELECT
                    area_id,
                    jsonb_agg(
                        jsonb_build_object(
                            'short_name', short_name,
                            'type',       mode_label,
                            'color',      color,
                            'text_color', text_color
                        )
                        ORDER BY
                            mode_rank,
                            NULLIF(REGEXP_REPLACE(COALESCE(short_name, ''), '\D', '', 'g'), '')::int NULLS LAST,
                            short_name
                    )::json AS routes_json
                FROM area_routes
                GROUP BY area_id
            )
            UPDATE gtfs_stop_area a
            SET routes = routes_agg.routes_json
            FROM routes_agg
            WHERE a.id = routes_agg.area_id
        SQL);

        $this->db->executeStatement(<<<'SQL'
            WITH area_modes AS (
                SELECT
                    s.area_id,
                    CASE r.type
                        WHEN 0  THEN 'tram'
                        WHEN 1  THEN 'metro'
                        WHEN 2  THEN 'train'
                        WHEN 11 THEN 'trolley'
                        ELSE         'bus'
                    END AS mode_label,
                    CASE r.type
                        WHEN 0  THEN 0
                        WHEN 1  THEN 1
                        WHEN 11 THEN 2
                        ELSE         3
                    END AS mode_rank
                FROM gtfs_stop_time st
                JOIN gtfs_stop  s ON s.id = st.stop_id
                JOIN gtfs_trip  t ON t.id = st.trip_id
                JOIN gtfs_route r ON r.id = t.route_id
                WHERE s.area_id IS NOT NULL
                GROUP BY s.area_id, r.type
            ),
            modes_agg AS (
                SELECT
                    area_id,
                    jsonb_agg(mode_label ORDER BY mode_rank)::json AS modes_json
                FROM area_modes
                GROUP BY area_id
            )
            UPDATE gtfs_stop_area a
            SET modes = modes_agg.modes_json
            FROM modes_agg
            WHERE a.id = modes_agg.area_id
        SQL);
    }

    /**
     * Lowercase, no accents, strips quay/direction suffixes that distinguish
     * physical quays of the same logical stop.
     */
    public static function normalize(string $name): string
    {
        $slug = (new AsciiSlugger('fr'))->slug($name)->lower()->toString();
        $name = str_replace('-', ' ', $slug);
        $name = trim(preg_replace('/\s+/', ' ', $name));

        // Strip "- quai 3" / "quai 12" trailing tokens.
        $name = preg_replace('/\s*-?\s*quai\s*\d+\s*$/u', '', $name);

        // Strip directional suffixes (require a separator before the direction
        // word/abbrev so that a name like "republique" doesn't lose its trailing "e").
        $name = preg_replace(
            '/\s+(direction|dir\.?|vers|->)\s*(nord|sud|est|ouest|n|s|e|o|w)\s*$/u',
            '',
            $name,
        );
        $name = preg_replace(
            '/\s+(nord|sud|est|ouest)\s*$/u',
            '',
            $name,
        );
        // Trailing single-letter direction (must be preceded by whitespace).
        $name = preg_replace('/\s+[nseow]\s*$/u', '', $name);

        // Strip single trailing letter quay code: "Charpennes B"
        $name = preg_replace('/\s+[a-z]$/u', '', $name);

        return trim($name);
    }

    /** Returns the user-facing label for the area (preserves accents, strips suffixes). */
    public static function displayName(string $original): string
    {
        $clean = preg_replace('/\s*-?\s*quai\s*\d+\s*$/iu', '', $original);
        $clean = preg_replace(
            '/\s+(direction|dir\.?|vers|->)?\s*(nord|sud|est|ouest|N|S|E|O|W)\s*$/iu',
            '',
            $clean,
        );
        $clean = preg_replace('/\s+[A-Z]$/u', '', $clean);
        return trim($clean);
    }

    /**
     * Build deterministic area id: slug + 6 hex chars from coords (stable across runs).
     */
    public static function buildAreaId(string $normalized, float $lat, float $lon, int $clusterIdx): string
    {
        $base = preg_replace('/\s+/', '-', $normalized) ?: 'area';
        $base = substr($base, 0, 60);
        $hash = substr(md5(sprintf('%s|%.4f|%.4f|%d', $normalized, $lat, $lon, $clusterIdx)), 0, 6);
        return $base . '-' . $hash;
    }

    /**
     * Greedy single-link clustering by haversine distance.
     *
     * @param  Stop[] $stops
     * @return Stop[][]
     */
    public function clusterByProximity(array $stops): array
    {
        if (\count($stops) <= 1) {
            return $stops ? [$stops] : [];
        }

        $clusters = [];
        foreach ($stops as $stop) {
            $assigned = false;
            foreach ($clusters as &$cluster) {
                [$cLat, $cLon] = self::centroid($cluster);
                $d = self::haversine($stop->getLatitude(), $stop->getLongitude(), $cLat, $cLon);
                if ($d <= self::CLUSTER_RADIUS_METERS) {
                    $cluster[] = $stop;
                    $assigned = true;
                    break;
                }
            }
            unset($cluster);
            if (!$assigned) {
                $clusters[] = [$stop];
            }
        }
        return $clusters;
    }

    /**
     * @param  Stop[] $cluster
     * @return array{0: float, 1: float}
     */
    public static function centroid(array $cluster): array
    {
        $lat = 0.0;
        $lon = 0.0;
        $n = \count($cluster);
        foreach ($cluster as $s) {
            $lat += $s->getLatitude();
            $lon += $s->getLongitude();
        }
        return [$lat / $n, $lon / $n];
    }

    /** @param Stop[] $cluster */
    public static function radius(array $cluster, float $cLat, float $cLon): int
    {
        $max = 0.0;
        foreach ($cluster as $s) {
            $d = self::haversine($s->getLatitude(), $s->getLongitude(), $cLat, $cLon);
            if ($d > $max) {
                $max = $d;
            }
        }
        return (int) ceil($max);
    }

    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private function truncate(): void
    {
        // DELETE (not TRUNCATE CASCADE): TRUNCATE ... CASCADE on a referenced
        // table also wipes dependent tables, which would nuke every gtfs_stop.
        // A plain DELETE FROM triggers the FK ON DELETE SET NULL action, which
        // is exactly what we want — area_id becomes NULL on every Stop.
        $this->db->executeStatement('DELETE FROM gtfs_stop_area');
    }
}
