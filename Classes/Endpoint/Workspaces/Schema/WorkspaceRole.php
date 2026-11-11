<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Neos\Domain\Model;

/**
 * What a workspace role allows, the lowercased values of Neos\Neos\Domain\Model\WorkspaceRole
 */
enum WorkspaceRole: string implements ProvidesSchema
{
    case VIEWER = 'viewer';
    case COLLABORATOR = 'collaborator';
    case MANAGER = 'manager';

    public function toWorkspaceRole(): Model\WorkspaceRole
    {
        return Model\WorkspaceRole::from(strtoupper($this->value));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'viewer (may read the workspace), collaborator (may also change its content and publish or discard the changes) or manager (may also change its title, description and roles and delete it)',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
