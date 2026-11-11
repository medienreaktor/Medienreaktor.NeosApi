<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\Service\WorkspaceService;

/**
 * Tests that need the content repository "default": it needs Testing/Ddev (MariaDB), the plain Testing context skips
 * them. requireContentRepository() sets it up empty, with Neos' tables that migrations create, not the entities, and
 * tearDown() empties it again for the other tests
 */
abstract class ContentRepositoryTestCase extends EndpointTestCase
{
    private bool $contentRepositorySetUp = false;

    protected function tearDown(): void
    {
        if ($this->contentRepositorySetUp) {
            $this->maintainer()->prune();
        }
        parent::tearDown();
    }

    /**
     * An empty content repository, and Neos' tables for asset usages, workspace metadata and roles and impending hard
     * removal conflicts
     */
    protected function requireContentRepository(): void
    {
        $connection = $this->objectManager->get(EntityManagerInterface::class)->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The content repository needs a MariaDB or MySQL database');
        }
        // as Neos.Neos' migration Version20240906102606
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `neos_asset_usage` (
                `contentrepositoryid` char(16) DEFAULT NULL,
                `assetid` varchar(40) NOT NULL DEFAULT '',
                `originalassetid` varchar(40) DEFAULT NULL,
                `workspacename` char(36) NOT NULL,
                `nodeaggregateid` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                `origindimensionspacepoint` json DEFAULT (JSON_OBJECT()),
                `origindimensionspacepointhash` varbinary(32) NOT NULL DEFAULT '',
                `propertyname` varchar(255) NOT NULL DEFAULT '',
                UNIQUE KEY `IDX_14C94F11044B499EB28F27DAEAC5D4BB` (`contentrepositoryid`, `assetid`, `originalassetid`, `workspacename`, `nodeaggregateid`, `origindimensionspacepointhash`, `propertyname`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        // as Neos.Neos' migrations Version20240425223900 and Version20241212000000
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `neos_neos_workspace_metadata` (
                `content_repository_id` varchar(16) NOT NULL,
                `workspace_name` varchar(255) NOT NULL,
                `title` varchar(255) NOT NULL,
                `description` longtext NOT NULL,
                `classification` varchar(255) NOT NULL,
                `owner_user_id` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`content_repository_id`, `workspace_name`),
                UNIQUE KEY `owner` (`content_repository_id`, `owner_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `neos_neos_workspace_role` (
                `content_repository_id` varchar(16) NOT NULL,
                `workspace_name` varchar(255) NOT NULL,
                `subject_type` varchar(20) NOT NULL,
                `subject` varchar(255) NOT NULL,
                `role` varchar(20) NOT NULL,
                PRIMARY KEY (`content_repository_id`, `workspace_name`, `subject_type`, `subject`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        // as Neos.Neos' migration Version20250224180749, its soft removal hooks need it
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `neos_neos_impending_hard_removal_conflict` (
                `content_repository_id` varchar(16) NOT NULL,
                `workspace_name` varchar(255) NOT NULL,
                `node_aggregate_id` varchar(64) NOT NULL,
                `dimension_space_points` json NOT NULL,
                PRIMARY KEY (`content_repository_id`, `workspace_name`, `node_aggregate_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        foreach (['neos_asset_usage', 'neos_neos_workspace_metadata', 'neos_neos_workspace_role', 'neos_neos_impending_hard_removal_conflict'] as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }
        $this->maintainer()->setUp();
        $this->maintainer()->prune();
        $this->contentRepositorySetUp = true;
    }

    /**
     * The content repository with live, as Neos creates it (its metadata and role assignments)
     */
    protected function requireLiveWorkspace(): void
    {
        $this->requireContentRepository();
        $this->withoutAuthorizationChecks(fn () => $this->objectManager->get(WorkspaceService::class)->createRootWorkspace(
            $this->contentRepository()->id,
            WorkspaceName::forLive(),
            WorkspaceTitle::fromString('Public live workspace'),
            WorkspaceDescription::fromString(''),
            WorkspaceRoleAssignments::createForLiveWorkspace(),
        ));
    }

    protected function contentRepository(): ContentRepository
    {
        return $this->objectManager->get(ContentRepositoryRegistry::class)->get(ContentRepositoryId::fromString('default'));
    }

    protected function handle(CommandInterface $command): void
    {
        $this->withoutAuthorizationChecks(fn () => $this->contentRepository()->handle($command));
    }

    protected function withoutAuthorizationChecks(\Closure $callback): mixed
    {
        return $this->objectManager->get(Context::class)->withoutAuthorizationChecks($callback);
    }

    private function maintainer(): ContentRepositoryMaintainer
    {
        return $this->objectManager->get(ContentRepositoryRegistry::class)->buildService(ContentRepositoryId::fromString('default'), new ContentRepositoryMaintainerFactory());
    }
}
