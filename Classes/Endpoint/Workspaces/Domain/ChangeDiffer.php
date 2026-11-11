<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Nodes\Schema\NodeName;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Endpoint\Workspaces\Schema\ChangeDiff;
use Neos\Api\Endpoint\Workspaces\Schema\NodeAggregateIdDiff;
use Neos\Api\Endpoint\Workspaces\Schema\NodeNameDiff;
use Neos\Api\Endpoint\Workspaces\Schema\NodeTypeDiff;
use Neos\Api\Endpoint\Workspaces\Schema\PropertyDiff;
use Neos\Api\Endpoint\Workspaces\Schema\PropertyDiffList;
use Neos\Api\Endpoint\Workspaces\Schema\ReferenceDiff;
use Neos\Api\Endpoint\Workspaces\Schema\ReferenceDiffList;
use Neos\Api\Endpoint\Workspaces\Schema\TagDiff;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\PendingChangesProjection;

/**
 * A pending change → what it does to its node compared to the base workspace (state, not events)
 *
 * The node is read in the workspace and in the base workspace in the change's dimension space point, without
 * visibility constraints, so hidden and removed nodes are compared as well. In the base workspace that may be another
 * variant shining through (a new specialization compared to its fallback). A root workspace has no base, and a base
 * the content repository won't let read is treated as none: everything is compared to nothing, as for a created node.
 * Like the projection, only Schema\Change and this class touch PendingChangesProjection's types
 */
#[Flow\Scope('singleton')]
final readonly class ChangeDiffer
{
    public function __construct(
        private NodeSerializer $nodeSerializer,
    ) {
    }

    public function diff(ReadableWorkspace $workspace, PendingChangesProjection\Change $change): ChangeDiff
    {
        // both looked up once per request, not per change
        $contentGraph = $workspace->contentGraph();
        $baseContentGraph = $workspace->contentGraph(base: true);
        $baseNodeAggregate = $baseContentGraph?->findNodeAggregateById($change->nodeAggregateId);
        // the projection marks a created node and a created variant of an existing one the same way
        $createdVariant = $change->created && $baseNodeAggregate !== null;

        if ($change->originDimensionSpacePoint === null) {
            // a change to the whole aggregate (node type, name): there are no variant-specific fields to compare
            return self::aggregateDiff($contentGraph->findNodeAggregateById($change->nodeAggregateId), $baseNodeAggregate);
        }
        if ($change->deleted) {
            // removed nodes need no fields, a node created and removed again would list all its properties
            return self::emptyDiff($createdVariant);
        }

        $dimensionSpacePoint = $change->originDimensionSpacePoint->toDimensionSpacePoint();
        $subgraph = $contentGraph->getSubgraph($dimensionSpacePoint, VisibilityConstraints::createEmpty());
        $baseSubgraph = $baseContentGraph?->getSubgraph($dimensionSpacePoint, VisibilityConstraints::createEmpty());
        $node = $subgraph->findNodeById($change->nodeAggregateId);
        if ($node === null) {
            // e.g. removed for good in the workspace
            return self::emptyDiff($createdVariant);
        }
        $baseNode = $baseSubgraph?->findNodeById($change->nodeAggregateId);

        return new ChangeDiff(
            $createdVariant,
            $baseNode !== null && !$baseNode->nodeTypeName->equals($node->nodeTypeName)
                ? new NodeTypeDiff(NodeTypeName::fromString($baseNode->nodeTypeName->value), NodeTypeName::fromString($node->nodeTypeName->value))
                : null,
            $baseNode !== null && $baseNode->name?->value !== $node->name?->value
                ? self::nameDiff($baseNode->name?->value, $node->name?->value)
                : null,
            $baseNode !== null && $baseSubgraph !== null ? self::parentDiff($subgraph, $baseSubgraph, $node) : null,
            $this->propertyDiffs($node, $baseNode),
            self::referenceDiffs($subgraph, $node, $baseSubgraph, $baseNode),
            self::tagDiff($node, $baseNode),
        );
    }

    private static function emptyDiff(bool $createdVariant): ChangeDiff
    {
        return new ChangeDiff($createdVariant, null, null, null, new PropertyDiffList(), new ReferenceDiffList(), null);
    }

    /**
     * Node type and name are the aggregate's, the same in every dimension space point
     */
    private static function aggregateDiff(?NodeAggregate $nodeAggregate, ?NodeAggregate $baseNodeAggregate): ChangeDiff
    {
        if ($nodeAggregate === null || $baseNodeAggregate === null) {
            return self::emptyDiff(false);
        }
        return new ChangeDiff(
            false,
            !$baseNodeAggregate->nodeTypeName->equals($nodeAggregate->nodeTypeName)
                ? new NodeTypeDiff(NodeTypeName::fromString($baseNodeAggregate->nodeTypeName->value), NodeTypeName::fromString($nodeAggregate->nodeTypeName->value))
                : null,
            $baseNodeAggregate->nodeName?->value !== $nodeAggregate->nodeName?->value
                ? self::nameDiff($baseNodeAggregate->nodeName?->value, $nodeAggregate->nodeName?->value)
                : null,
            null,
            new PropertyDiffList(),
            new ReferenceDiffList(),
            null,
        );
    }

    private static function nameDiff(?string $old, ?string $new): NodeNameDiff
    {
        return new NodeNameDiff(
            $old !== null ? NodeName::fromString($old) : null,
            $new !== null ? NodeName::fromString($new) : null,
        );
    }

    /**
     * Only a new parent: a move among the siblings has no state to compare that isn't changed by the siblings as well
     */
    private static function parentDiff(ContentSubgraphInterface $subgraph, ContentSubgraphInterface $baseSubgraph, Node $node): ?NodeAggregateIdDiff
    {
        $parentId = $subgraph->findParentNode($node->aggregateId)?->aggregateId;
        $baseParentId = $baseSubgraph->findParentNode($node->aggregateId)?->aggregateId;
        if ($parentId === null || $baseParentId === null || $parentId->equals($baseParentId)) {
            return null;
        }
        return new NodeAggregateIdDiff(NodeAggregateId::fromString($baseParentId->value), NodeAggregateId::fromString($parentId->value));
    }

    /**
     * Compared by their serialized values, as the content repository stores them; returned as Node's properties
     */
    private function propertyDiffs(Node $node, ?Node $baseNode): PropertyDiffList
    {
        $stored = $node->properties->serialized()->getPlainValues();
        $baseStored = $baseNode?->properties->serialized()->getPlainValues() ?? [];
        $names = [];
        foreach (array_keys($stored + $baseStored) as $name) {
            // decoded JSON values are identical exactly when their serialization is
            if (($stored[$name] ?? null) !== ($baseStored[$name] ?? null)) {
                $names[] = (string)$name;
            }
        }
        if ($names === []) {
            return new PropertyDiffList();
        }
        sort($names);
        $values = $this->nodeSerializer->properties($node->properties);
        $baseValues = $baseNode !== null ? $this->nodeSerializer->properties($baseNode->properties) : [];
        return new PropertyDiffList(...array_map(
            static fn (string $name) => new PropertyDiff($name, $baseValues[$name] ?? null, $values[$name] ?? null),
            $names,
        ));
    }

    /**
     * Compared by the referenced aggregates and their order, not the properties of the references
     */
    private static function referenceDiffs(ContentSubgraphInterface $subgraph, Node $node, ?ContentSubgraphInterface $baseSubgraph, ?Node $baseNode): ReferenceDiffList
    {
        $targets = self::referenceTargets($subgraph, $node);
        $baseTargets = $baseSubgraph !== null && $baseNode !== null ? self::referenceTargets($baseSubgraph, $baseNode) : [];
        $names = array_keys($targets + $baseTargets);
        sort($names);
        $diffs = [];
        foreach ($names as $name) {
            $new = $targets[$name] ?? [];
            $old = $baseTargets[$name] ?? [];
            if ($new !== $old) {
                $diffs[] = new ReferenceDiff((string)$name, $old, $new);
            }
        }
        return new ReferenceDiffList(...$diffs);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function referenceTargets(ContentSubgraphInterface $subgraph, Node $node): array
    {
        $targets = [];
        foreach ($subgraph->findReferences($node->aggregateId, FindReferencesFilter::create()) as $reference) {
            $targets[$reference->name->value][] = $reference->node->aggregateId->value;
        }
        return $targets;
    }

    /**
     * The node's own tags, not inherited ones: a node in a hidden subtree didn't change itself. The removed tag is
     * the change's deleted flag
     */
    private static function tagDiff(Node $node, ?Node $baseNode): ?TagDiff
    {
        $tags = $node->tags->withoutInherited()->without(NeosSubtreeTag::removed())->toStringArray();
        $baseTags = $baseNode?->tags->withoutInherited()->without(NeosSubtreeTag::removed())->toStringArray() ?? [];
        $added = array_values(array_diff($tags, $baseTags));
        $removed = array_values(array_diff($baseTags, $tags));
        if ($added === [] && $removed === []) {
            return null;
        }
        sort($added);
        sort($removed);
        return new TagDiff($added, $removed);
    }
}
