<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What a change does to its node compared to the base workspace: what publishing it would apply
 *
 * The net difference of the node's state in the workspace and in the base workspace, in the same dimension space
 * point, not the events that led there: a value changed and changed back is no difference
 */
final readonly class ChangeDiff implements ProvidesSchema
{
    /**
     * @param bool $createdVariant whether the created node is a new variant (in another dimension space point) of a node that exists in the base workspace already, not a new node: compared to what the base workspace shows in its dimension space point, e.g. the fallback, if anything
     * @param NodeTypeDiff|null $nodeType the node type if it changed, else null (also for nodes that don't exist in the base workspace)
     * @param NodeNameDiff|null $name the node name if it changed, else null (also for nodes that don't exist in the base workspace)
     * @param NodeAggregateIdDiff|null $parentAggregateId the parent node if the node was moved to another one, else null. A node moved among its siblings (moved, but no parentAggregateId) has the same parent
     * @param PropertyDiffList $properties the properties whose values differ, sorted by name, every set property for a node that doesn't exist in the base workspace. Empty for removed nodes and changes to the whole aggregate
     * @param ReferenceDiffList $references the references whose referenced nodes differ (not the properties of the references), sorted by name. Empty for removed nodes and changes to the whole aggregate
     * @param TagDiff|null $tags the tags set on the node itself that differ, else null. Null for removed nodes and changes to the whole aggregate
     */
    public function __construct(
        public bool $createdVariant,
        public NodeTypeDiff|null $nodeType,
        public NodeNameDiff|null $name,
        public NodeAggregateIdDiff|null $parentAggregateId,
        public PropertyDiffList $properties,
        public ReferenceDiffList $references,
        public TagDiff|null $tags,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
