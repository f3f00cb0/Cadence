<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enable the PostgreSQL `unaccent` extension so stop / stop-area name searches
 * can be made accent-insensitive ("Cité du design" matches the stored
 * "Cite du design", and vice versa) via the UNACCENT() DQL function.
 */
final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the unaccent extension for accent-insensitive name search.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
    }
}
