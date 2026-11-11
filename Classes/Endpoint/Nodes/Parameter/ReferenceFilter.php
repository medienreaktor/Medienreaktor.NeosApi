<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Parameter;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * The nodes that reference a node: ?filterByReference[nodeAggregateId]=…&filterByReference[name]=relatedPages
 */
final readonly class ReferenceFilter implements ProvidesSchema
{
    public function __construct(
        public NodeAggregateId $nodeAggregateId,
        public string|null $name = null,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The nodes that reference the node with the aggregate id, a node once per reference, with name only the references of that name',
            properties: ObjectProperties::create(
                nodeAggregateId: NodeAggregateId::schema(),
                name: StringSchema::create(description: 'Only the references of this name', examples: ['relatedPages'], minLength: 1),
            ),
            additionalProperties: false,
            required: ['nodeAggregateId'],
        );
    }
}
