<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds gtfs_stop_area and the area_id foreign key on gtfs_stop.
 */
final class Version20260520170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add StopArea entity and Stop.area_id relation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gtfs_stop_area (id VARCHAR(80) NOT NULL, name VARCHAR(255) NOT NULL, latitude NUMERIC(9, 6) NOT NULL, longitude NUMERIC(9, 6) NOT NULL, bounding_radius INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_stop_area_name ON gtfs_stop_area (name)');
        $this->addSql('CREATE INDEX idx_stop_area_coords ON gtfs_stop_area (latitude, longitude)');

        $this->addSql('ALTER TABLE gtfs_stop ADD area_id VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE gtfs_stop ADD CONSTRAINT FK_GTFS_STOP_AREA FOREIGN KEY (area_id) REFERENCES gtfs_stop_area (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_stop_area_id ON gtfs_stop (area_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gtfs_stop DROP CONSTRAINT FK_GTFS_STOP_AREA');
        $this->addSql('DROP INDEX idx_stop_area_id');
        $this->addSql('ALTER TABLE gtfs_stop DROP area_id');
        $this->addSql('DROP TABLE gtfs_stop_area');
    }
}
