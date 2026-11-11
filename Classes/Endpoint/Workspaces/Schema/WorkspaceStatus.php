<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * Whether a workspace is based on the latest state of its base workspace, the lowercased values of
 * Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceStatus
 */
enum WorkspaceStatus: string implements ProvidesSchema
{
    case UP_TO_DATE = 'up_to_date';
    case OUTDATED = 'outdated';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'up_to_date, or outdated if its base workspace changed since and it needs a rebase',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
