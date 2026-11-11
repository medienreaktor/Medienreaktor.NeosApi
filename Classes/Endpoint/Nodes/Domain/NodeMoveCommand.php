<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Domain;

use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * moveNode's algorithm: the MoveNodeAggregate Neos UI's MoveInto/MoveBefore would send. Not RequestBody\NodeMove, the
 * body of the operation
 */
#[Flow\Scope('singleton')]
final readonly class NodeMoveCommand
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
    ) {
    }

    /**
     * The command moving the node below the parent, before the sibling if given (a child of the parent), else as its
     * last child. As Neos UI: the parent only if it changes, and the other neighbour as preceding sibling, which the
     * content repository uses in points the sibling isn't in
     *
     * @throws \RuntimeException if the node type's options.moveNodeStrategy is invalid
     */
    public function create(ContentGraph\Node $node, ContentGraph\Node $parent, ?ContentGraph\Node $sibling): MoveNodeAggregate
    {
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        $nodeType = $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        return MoveNodeAggregate::create(
            $node->workspaceName,
            $node->dimensionSpacePoint,
            $node->aggregateId,
            self::strategy($nodeType),
            $subgraph->findParentNode($node->aggregateId)?->aggregateId->equals($parent->aggregateId) === true ? null : $parent->aggregateId,
            self::precedingSibling($subgraph, $parent, $node, $sibling),
            $sibling?->aggregateId,
        );
    }

    /**
     * How the node's other variants move along, as Neos UI: the node type's options.moveNodeStrategy, which Neos UI's
     * node types configure (gatherAll for documents, scatter for content), gatherAll without one, the safe choice
     */
    private static function strategy(?NodeType $nodeType): RelationDistributionStrategy
    {
        $configured = $nodeType?->getConfiguration('options.moveNodeStrategy');
        if ($configured === null) {
            return RelationDistributionStrategy::STRATEGY_GATHER_ALL;
        }
        return is_string($configured) && ($strategy = RelationDistributionStrategy::tryFrom($configured)) !== null
            ? $strategy
            : throw new \RuntimeException(sprintf('The node type %s has an invalid options.moveNodeStrategy %s', $nodeType->name->value, json_encode($configured)), 1791462318);
    }

    /**
     * The child of the parent before the sibling, or its last child without one, leaving out the node itself: where the
     * node goes in points the sibling isn't in. Null if there is none, also if the sibling isn't a child of the parent
     */
    private static function precedingSibling(ContentSubgraphInterface $subgraph, ContentGraph\Node $parent, ContentGraph\Node $node, ?ContentGraph\Node $sibling): ?NodeAggregateId
    {
        $preceding = null;
        foreach ($subgraph->findChildNodes($parent->aggregateId, FindChildNodesFilter::create()) as $child) {
            if ($sibling !== null && $child->aggregateId->equals($sibling->aggregateId)) {
                return $preceding;
            }
            if (!$child->aggregateId->equals($node->aggregateId)) {
                $preceding = $child->aggregateId;
            }
        }
        return $sibling === null ? $preceding : null;
    }
}
