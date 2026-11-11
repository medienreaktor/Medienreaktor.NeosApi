<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\RequestBody;

use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceClassification;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceVisibility;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A workspace to create, shared or the caller's personal one
 *
 * There is no applyTo(): a workspace is created by a command, which Neos' WorkspaceService sends with its metadata
 */
final readonly class WorkspaceCreate implements ProvidesSchema
{
    /**
     * @param WorkspaceClassification|null $classification shared if left out, or personal: the caller's own, one per user and content repository. root and unknown can't be created
     * @param WorkspaceName|null $baseWorkspaceName the workspace changes are published to, one the account may read, live if left out
     * @param string|null $title the title Neos shows, trimmed, 1 to 200 letters, digits, spaces and punctuation. Required for a shared workspace, the user's name for a personal one if left out
     * @param string|null $description up to 500 letters, digits, spaces and punctuation, empty if left out
     * @param WorkspaceVisibility|null $visibility only for a shared workspace, public if left out
     * @param WorkspaceName|null $name the name of the new workspace, unique in the content repository. If left out, derived from the title (shared) or the user's name (personal) as Neos does, with a number appended if it's taken
     */
    public function __construct(
        public WorkspaceClassification|null $classification = null,
        public WorkspaceName|null $baseWorkspaceName = null,
        public string|null $title = null,
        public string|null $description = null,
        public WorkspaceVisibility|null $visibility = null,
        public WorkspaceName|null $name = null,
    ) {
    }

    public function classification(): WorkspaceClassification
    {
        return $this->classification ?? WorkspaceClassification::SHARED;
    }

    public function visibility(): WorkspaceVisibility
    {
        return $this->visibility ?? WorkspaceVisibility::PUBLIC;
    }

    public function baseWorkspaceName(): WorkspaceName
    {
        return $this->baseWorkspaceName ?? WorkspaceName::fromString('live');
    }

    /**
     * The title, null if left out
     *
     * @throws \InvalidArgumentException if it's empty or has characters Neos doesn't allow
     */
    public function workspaceTitle(): ?Model\WorkspaceTitle
    {
        return $this->title !== null ? Model\WorkspaceTitle::fromString(trim($this->title)) : null;
    }

    /**
     * @throws \InvalidArgumentException if it's too long or has characters Neos doesn't allow
     */
    public function workspaceDescription(): Model\WorkspaceDescription
    {
        return Model\WorkspaceDescription::fromString($this->description ?? '');
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
