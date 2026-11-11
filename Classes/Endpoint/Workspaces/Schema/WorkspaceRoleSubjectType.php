<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * Whom a workspace role is assigned to, the lowercased values of Neos\Neos\Domain\Model\WorkspaceRoleSubjectType
 */
enum WorkspaceRoleSubjectType: string implements ProvidesSchema
{
    case USER = 'user';
    case GROUP = 'group';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'user (a Neos user, by its ID) or group (every account with a Flow role, by the role identifier)',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
