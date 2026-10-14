<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Where a copy of a node goes: below a parent, optionally before a sibling, in a dimension space point
 *
 * There is no applyTo(): a node is copied by commands, which Neos' NodeDuplicationService builds
 */
final readonly class NodeCopy implements ProvidesSchema
{
    /**
     * @param NodeAggregateId $parentNodeAggregateId the parent of the copy, without succeedingSiblingNodeAggregateId the copy becomes its last child
     * @param NodeAggregateId|null $succeedingSiblingNodeAggregateId the node to put the copy before, a child of parentNodeAggregateId
     * @param DimensionSpacePoint|null $targetDimensionSpacePoint the dimension space point to create the copy in, its origin, as JSON. If left out, the one the node is read in
     */
    public function __construct(
        public NodeAggregateId $parentNodeAggregateId,
        public NodeAggregateId|null $succeedingSiblingNodeAggregateId = null,
        public DimensionSpacePoint|null $targetDimensionSpacePoint = null,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
