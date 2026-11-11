<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\RequestBody;

use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceRole;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The workspace role a user or group is to have, the subject is in the path
 *
 * There is no applyTo(): roles are assigned by Neos' WorkspaceService, there is no model
 */
final readonly class WorkspaceRoleAssign implements ProvidesSchema
{
    /**
     * @param WorkspaceRole $role what the subject may do in the workspace
     */
    public function __construct(
        public WorkspaceRole $role,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
