<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Denormalized StopArea.modes / StopArea.routes so the map can paint
 * mode-coloured markers (tram vs bus vs trolley) and per-stop line chips
 * without a join at request time.
 */
final class Version20260522150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add modes/routes JSON columns to gtfs_stop_area and backfill from existing GTFS data.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE gtfs_stop_area ADD modes JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE gtfs_stop_area ADD routes JSON NOT NULL DEFAULT '[]'");

        // Backfill: pair each area with the distinct routes that touch its stops,
        // ordered (tram first, then numeric short_name ascending) for predictable
        // chip rendering. Areas with no routes keep the '[]' default.
        $this->addSql(<<<'SQL'
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

        $this->addSql(<<<'SQL'
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

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gtfs_stop_area DROP COLUMN routes');
        $this->addSql('ALTER TABLE gtfs_stop_area DROP COLUMN modes');
    }
}
