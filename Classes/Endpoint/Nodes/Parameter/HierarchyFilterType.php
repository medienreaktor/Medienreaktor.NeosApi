<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Parameter;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * What the nodes listed are to the node of a HierarchyFilter
 */
enum HierarchyFilterType: string implements ProvidesSchema
{
    case CHILD_OF = 'childOf';
    case DESCENDANT_OF = 'descendantOf';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'childOf for its direct child nodes, descendantOf for all nodes below it',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
