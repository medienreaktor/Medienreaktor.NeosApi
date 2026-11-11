<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Where a node moves to, as for createNode: below a parent, before a sibling, at least one of them
 *
 * There is no applyTo(): a node is moved by a command, which the endpoint builds
 */
final readonly class NodeMove implements ProvidesSchema
{
    /**
     * @param NodeAggregateId|null $parentNodeAggregateId the new parent, without succeedingSiblingNodeAggregateId the node becomes its last child. If left out, the sibling's parent
     * @param NodeAggregateId|null $succeedingSiblingNodeAggregateId the node to move the node before, a child of parentNodeAggregateId if given
     */
    public function __construct(
        public NodeAggregateId|null $parentNodeAggregateId = null,
        public NodeAggregateId|null $succeedingSiblingNodeAggregateId = null,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
