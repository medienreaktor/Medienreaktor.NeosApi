<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add token record indexes for pruning and revocation queries
 */
final class Version20260724100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add token record indexes for pruning and revocation queries';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            "Migration can only be executed safely on 'Doctrine\DBAL\Platforms\PostgreSQLPlatform'."
        );

        $this->addSql('CREATE INDEX idx_neosapi_tokenrecord_expiresat ON medienreaktor_neosapi_domain_model_tokenrecord (expiresat)');
        $this->addSql('CREATE INDEX idx_neosapi_tokenrecord_clientidentifier ON medienreaktor_neosapi_domain_model_tokenrecord (clientidentifier)');
        $this->addSql('CREATE INDEX idx_neosapi_tokenrecord_accountidentifier ON medienreaktor_neosapi_domain_model_tokenrecord (accountidentifier)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            "Migration can only be executed safely on 'Doctrine\DBAL\Platforms\PostgreSQLPlatform'."
        );

        $this->addSql('DROP INDEX idx_neosapi_tokenrecord_expiresat');
        $this->addSql('DROP INDEX idx_neosapi_tokenrecord_clientidentifier');
        $this->addSql('DROP INDEX idx_neosapi_tokenrecord_accountidentifier');
    }
}
