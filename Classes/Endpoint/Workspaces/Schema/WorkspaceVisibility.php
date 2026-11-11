<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Neos\Domain\Model;

/**
 * Who may work in a new shared workspace besides its creator: Neos' role assignments for shared and private workspaces
 */
enum WorkspaceVisibility: string implements ProvidesSchema
{
    case PUBLIC = 'public';
    case PRIVATE = 'private';

    public function roleAssignmentsFor(Model\UserId $creator): Model\WorkspaceRoleAssignments
    {
        return match ($this) {
            self::PUBLIC => Model\WorkspaceRoleAssignments::createForSharedWorkspace($creator),
            self::PRIVATE => Model\WorkspaceRoleAssignments::createForPrivateWorkspace($creator),
        };
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'public (the creator manages it, every editor may collaborate in it) or private (only the creator manages it, as in the Neos workspace module)',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
