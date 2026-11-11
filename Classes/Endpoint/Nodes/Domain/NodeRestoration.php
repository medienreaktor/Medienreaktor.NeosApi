<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\SharedModel;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

/**
 * restoreNode's algorithm: undoing deleteNode as Neos' trash does (RestoreController in Neos.Workspace.Ui)
 */
#[Flow\Scope('singleton')]
final readonly class NodeRestoration
{
    /**
     * Whether the aggregate is removed itself in a dimension space point, not just inside a removed node
     */
    public function isRemoved(NodeAggregate $nodeAggregate): bool
    {
        return !$nodeAggregate->getCoveredDimensionsTaggedBy(NeosSubtreeTag::removed(), withoutInherited: true)->isEmpty();
    }

    /**
     * Untags removed with STRATEGY_ALL_VARIANTS in a point the aggregate is removed in, so it comes back wherever it
     * was removed, then the same for each ancestor removed itself, so it has its place in the tree again
     *
     * @param NodeAggregate $nodeAggregate one that isRemoved()
     * @throws \Exception the content repository's, e.g. AccessDenied
     */
    public function restore(ContentRepository $contentRepository, ContentGraphInterface $contentGraph, NodeAggregate $nodeAggregate): void
    {
        foreach ([$nodeAggregate, ...$this->removedAncestors($nodeAggregate->nodeAggregateId, $contentGraph)] as $removed) {
            $points = $removed->getCoveredDimensionsTaggedBy(NeosSubtreeTag::removed(), withoutInherited: true)->points;
            $contentRepository->handle(UntagSubtree::create(
                $contentGraph->getWorkspaceName(),
                $removed->nodeAggregateId,
                reset($points) ?: throw new \LogicException('A removed node aggregate is removed somewhere', 1791449421),
                NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
                NeosSubtreeTag::removed(),
            ));
        }
    }

    /**
     * The ancestors that are removed themselves, in any dimension space point, nearest first, as Neos' trash restores
     * them with the node (RestoreController::gatherAdditionalAncestorsThatWillBeRestored())
     *
     * @return list<NodeAggregate>
     */
    private function removedAncestors(SharedModel\Node\NodeAggregateId $id, ContentGraphInterface $contentGraph): array
    {
        $removed = [];
        foreach ($contentGraph->findParentNodeAggregates($id) as $parent) {
            if ($this->isRemoved($parent)) {
                $removed[$parent->nodeAggregateId->value] = $parent;
            }
            // a node may have other parents in other dimension space points, their ancestors may meet
            foreach ($this->removedAncestors($parent->nodeAggregateId, $contentGraph) as $ancestor) {
                $removed[$ancestor->nodeAggregateId->value] ??= $ancestor;
            }
        }
        return array_values($removed);
    }
}
