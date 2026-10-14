<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * What the variants of a node say about themselves: how many there are, and where the node is shown
 */
final readonly class NodeVariantListingMeta implements ProvidesSchema
{
    public function __construct(
        public int $total,
        public DimensionSpacePointList $coveredDimensionSpacePoints,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'What the list says about itself beyond its items',
            properties: ObjectProperties::create(
                total: IntegerSchema::create(description: 'How many variants are listed', minimum: 0),
                coveredDimensionSpacePoints: DimensionSpacePointList::schema(),
            ),
            additionalProperties: false,
            required: ['total', 'coveredDimensionSpacePoints'],
        );
    }
}
