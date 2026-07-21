<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create tables for OAuth clients and token records
 */
final class Version20260721100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tables for OAuth clients and token records';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            "Migration can only be executed safely on 'Doctrine\DBAL\Platforms\PostgreSQLPlatform'."
        );

        $this->addSql('CREATE TABLE medienreaktor_neosapi_domain_model_oauthclient (persistence_object_identifier VARCHAR(40) NOT NULL, identifier VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, secrethash VARCHAR(255) DEFAULT NULL, redirecturis JSON NOT NULL, granttypes JSON NOT NULL, allowedscopes JSON NOT NULL, firstparty BOOLEAN NOT NULL, createdat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(persistence_object_identifier))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F8A4728C772E836A ON medienreaktor_neosapi_domain_model_oauthclient (identifier)');
        $this->addSql('COMMENT ON COLUMN medienreaktor_neosapi_domain_model_oauthclient.createdat IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE medienreaktor_neosapi_domain_model_tokenrecord (persistence_object_identifier VARCHAR(40) NOT NULL, identifier VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, clientidentifier VARCHAR(255) NOT NULL, accountidentifier VARCHAR(255) DEFAULT NULL, scopes JSON NOT NULL, expiresat TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked BOOLEAN NOT NULL, PRIMARY KEY(persistence_object_identifier))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_80090718772E836A ON medienreaktor_neosapi_domain_model_tokenrecord (identifier)');
        $this->addSql('COMMENT ON COLUMN medienreaktor_neosapi_domain_model_tokenrecord.expiresat IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            "Migration can only be executed safely on 'Doctrine\DBAL\Platforms\PostgreSQLPlatform'."
        );

        $this->addSql('DROP TABLE medienreaktor_neosapi_domain_model_oauthclient');
        $this->addSql('DROP TABLE medienreaktor_neosapi_domain_model_tokenrecord');
    }
}
