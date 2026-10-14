<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * What the node types say about themselves: how many there are, and the groups they are offered in
 */
final readonly class NodeTypeListingMeta implements ProvidesSchema
{
    public function __construct(
        public int $total,
        public NodeTypeGroupList $groups,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'What the list says about itself beyond its items',
            properties: ObjectProperties::create(
                total: IntegerSchema::create(description: 'How many node types are listed', minimum: 0),
                groups: NodeTypeGroupList::schema(),
            ),
            additionalProperties: false,
            required: ['total', 'groups'],
        );
    }
}
