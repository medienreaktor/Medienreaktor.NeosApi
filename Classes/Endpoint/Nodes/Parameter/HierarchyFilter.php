<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Parameter;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * The nodes below a node: ?filterByHierarchy[type]=childOf&filterByHierarchy[nodeAggregateId]=…
 */
final readonly class HierarchyFilter implements ProvidesSchema
{
    public function __construct(
        public HierarchyFilterType $type,
        public NodeAggregateId $nodeAggregateId,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The nodes below the node with the aggregate id: with type childOf its direct child nodes (in their order unless sorted), with type descendantOf all nodes below it',
            properties: ObjectProperties::create(
                type: HierarchyFilterType::schema(),
                nodeAggregateId: NodeAggregateId::schema(),
            ),
            additionalProperties: false,
            required: ['type', 'nodeAggregateId'],
        );
    }
}
