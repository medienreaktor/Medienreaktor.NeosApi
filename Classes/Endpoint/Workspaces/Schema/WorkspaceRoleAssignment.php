<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A workspace role of a user or group: one per subject and workspace
 */
final readonly class WorkspaceRoleAssignment implements ProvidesSchema
{
    /**
     * @param WorkspaceRoleSubject $subject the user or group
     * @param WorkspaceRole $role what it may do in the workspace
     */
    public function __construct(
        public WorkspaceRoleSubject $subject,
        public WorkspaceRole $role,
    ) {
    }

    public static function from(Model\WorkspaceRoleAssignment $assignment): self
    {
        return new self(WorkspaceRoleSubject::from($assignment->subject), WorkspaceRole::from(strtolower($assignment->role->value)));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
