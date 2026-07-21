<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create tables for OAuth clients and token records
 */
final class Version20260721100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tables for OAuth clients and token records';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            "Migration can only be executed safely on 'Doctrine\DBAL\Platforms\AbstractMySQLPlatform'."
        );

        $this->addSql('CREATE TABLE medienreaktor_neosapi_domain_model_oauthclient (persistence_object_identifier VARCHAR(40) NOT NULL, identifier VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, secrethash VARCHAR(255) DEFAULT NULL, redirecturis JSON NOT NULL COMMENT \'(DC2Type:json)\', granttypes JSON NOT NULL COMMENT \'(DC2Type:json)\', allowedscopes JSON NOT NULL COMMENT \'(DC2Type:json)\', firstparty TINYINT(1) NOT NULL, createdat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_F8A4728C772E836A (identifier), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE medienreaktor_neosapi_domain_model_tokenrecord (persistence_object_identifier VARCHAR(40) NOT NULL, identifier VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, clientidentifier VARCHAR(255) NOT NULL, accountidentifier VARCHAR(255) DEFAULT NULL, scopes JSON NOT NULL COMMENT \'(DC2Type:json)\', expiresat DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_80090718772E836A (identifier), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            "Migration can only be executed safely on 'Doctrine\DBAL\Platforms\AbstractMySQLPlatform'."
        );

        $this->addSql('DROP TABLE medienreaktor_neosapi_domain_model_oauthclient');
        $this->addSql('DROP TABLE medienreaktor_neosapi_domain_model_tokenrecord');
    }
}
