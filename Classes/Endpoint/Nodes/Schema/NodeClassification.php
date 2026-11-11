<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * How a node came to be, the values of Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification
 */
enum NodeClassification: string implements ProvidesSchema
{
    case REGULAR = 'regular';
    case TETHERED = 'tethered';
    case ROOT = 'root';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'regular, tethered (created with its parent, can\'t be removed or moved) or root',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
