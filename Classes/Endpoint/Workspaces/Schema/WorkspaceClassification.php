<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * What a workspace is for, the lowercased values of Neos\Neos\Domain\Model\WorkspaceClassification
 */
enum WorkspaceClassification: string implements ProvidesSchema
{
    case ROOT = 'root';
    case PERSONAL = 'personal';
    case SHARED = 'shared';
    case UNKNOWN = 'unknown';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'root (live, without a base workspace), personal (a user\'s own), shared (for several users) or unknown (not created through Neos)',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
