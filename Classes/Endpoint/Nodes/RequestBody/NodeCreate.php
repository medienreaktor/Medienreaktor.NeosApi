<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A node to create below a parent
 *
 * There is no applyTo(): a node is created by commands, which the endpoint builds. The schema is written out,
 * properties and references are maps, which schematic can't discover from an array
 */
final readonly class NodeCreate implements ProvidesSchema
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $references
     * @param array<string, string> $tetheredDescendantNodeAggregateIds by node path, e.g. main
     */
    public function __construct(
        public NodeTypeName $nodeType,
        public NodeAggregateId $parentNodeAggregateId,
        public NodeAggregateId|null $succeedingSiblingNodeAggregateId = null,
        public NodeAggregateId|null $nodeAggregateId = null,
        public array $properties = [],
        public array $references = [],
        public array $tetheredDescendantNodeAggregateIds = [],
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A node to create below a parent',
            properties: ObjectProperties::create(
                nodeType: NodeTypeName::schema(),
                parentNodeAggregateId: NodeAggregateId::schema(),
                succeedingSiblingNodeAggregateId: Nullable::wrap(NodeAggregateId::schema()),
                nodeAggregateId: Nullable::wrap(NodeAggregateId::schema()),
                properties: ObjectSchema::create(
                    description: 'The properties to set, as for updateNodeProperties, over the node type\'s defaults. A document without uriPathSegment gets one from its title, as in the Neos backend',
                    examples: [['title' => 'About us']],
                    additionalProperties: true,
                ),
                references: ObjectSchema::create(
                    description: 'The references to set, by name as for updateNodeReferences: [{"nodeAggregateId": "…", "properties": {…}}], properties optional. Set right after the node is created (SetNodeReferences): if they can\'t be, the node stays without them',
                    examples: [['relatedPages' => [['nodeAggregateId' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3']]]],
                    additionalProperties: true,
                ),
                tetheredDescendantNodeAggregateIds: ObjectSchema::create(
                    description: 'The aggregate ids of tethered descendants by node path (e.g. main, or main/teaser below it), the others get new ones. To know them before the node exists, e.g. to create nodes in its main collection right after it',
                    examples: [['main' => '5a1b8c2d-3e4f-4a5b-8c6d-7e8f9a0b1c2d']],
                    additionalProperties: NodeAggregateId::schema(),
                ),
            ),
            additionalProperties: false,
            required: ['nodeType', 'parentNodeAggregateId'],
        );
    }
}
