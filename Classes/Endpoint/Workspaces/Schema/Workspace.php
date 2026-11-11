<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Users\Schema\UserId;
use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A workspace of a content repository with its Neos metadata
 */
final readonly class Workspace implements ProvidesSchema
{
    /**
     * @param WorkspaceName|null $baseWorkspaceName the workspace changes are published to, null for a root workspace
     * @param string $title the title Neos shows, the name if none was given
     * @param string $description empty if none was given
     * @param UserId|null $ownerId the Neos user whose personal workspace it is, null for the other classifications
     * @param bool $hasPublishableChanges whether it has changes not yet published to its base workspace
     */
    public function __construct(
        public WorkspaceName $name,
        public WorkspaceName|null $baseWorkspaceName,
        public string $title,
        public string $description,
        public WorkspaceClassification $classification,
        public UserId|null $ownerId,
        public WorkspaceStatus $status,
        public bool $hasPublishableChanges,
        public WorkspacePermissions $permissions,
    ) {
    }

    public static function from(SharedModel\Workspace\Workspace $workspace, Model\WorkspaceMetadata $metadata, Model\WorkspacePermissions $permissions): self
    {
        return new self(
            WorkspaceName::fromString($workspace->workspaceName->value),
            $workspace->baseWorkspaceName !== null ? WorkspaceName::fromString($workspace->baseWorkspaceName->value) : null,
            $metadata->title->value,
            $metadata->description->value,
            WorkspaceClassification::from(strtolower($metadata->classification->value)),
            $metadata->ownerUserId !== null ? UserId::fromString($metadata->ownerUserId->value) : null,
            WorkspaceStatus::from(strtolower($workspace->status->value)),
            $workspace->hasPublishableChanges(),
            WorkspacePermissions::from($permissions),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
