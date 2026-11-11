<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel;
use Neos\Flow\Annotations as Flow;

/**
 * Orders a selection of documents by their ancestry in a workspace, for publishing or discarding them one by one (the
 * content repository has no command for several documents): publishing a new subpage needs its new parent in the
 * base workspace first, discarding a new parent page first would leave its new subpage without a parent
 */
#[Flow\Proxy(false)]
final readonly class DocumentAncestry
{
    private function __construct(
        private ContentGraphInterface $contentGraph,
    ) {
    }

    /**
     * The workspace's content graph, where the documents are (also removed ones, which are only tagged)
     */
    public static function in(ContentGraphInterface $contentGraph): self
    {
        return new self($contentGraph);
    }

    /**
     * Every selected ancestor of a selected document before it, for publishing
     *
     * @param list<SharedModel\Node\NodeAggregateId> $documentAggregateIds
     * @return list<SharedModel\Node\NodeAggregateId>
     */
    public function ancestorsFirst(array $documentAggregateIds): array
    {
        return $this->order($documentAggregateIds, true);
    }

    /**
     * Every selected ancestor of a selected document after it, for discarding
     *
     * @param list<SharedModel\Node\NodeAggregateId> $documentAggregateIds
     * @return list<SharedModel\Node\NodeAggregateId>
     */
    public function descendantsFirst(array $documentAggregateIds): array
    {
        return $this->order($documentAggregateIds, false);
    }

    /**
     * Sorted by how many of the other selected documents are ancestors of each one, in any dimension space point the
     * document occupies. Unrelated documents keep their order (usort is stable), so do documents not found in the
     * workspace, which have no known ancestry
     *
     * @param list<SharedModel\Node\NodeAggregateId> $documentAggregateIds
     * @return list<SharedModel\Node\NodeAggregateId>
     */
    private function order(array $documentAggregateIds, bool $ancestorsFirst): array
    {
        if (count($documentAggregateIds) < 2) {
            return $documentAggregateIds;
        }
        $selected = [];
        foreach ($documentAggregateIds as $documentAggregateId) {
            $selected[$documentAggregateId->value] = true;
        }
        $selectedAncestorCount = [];
        foreach ($documentAggregateIds as $documentAggregateId) {
            $ancestorIds = [];
            $nodeAggregate = $this->contentGraph->findNodeAggregateById($documentAggregateId);
            foreach ($nodeAggregate->occupiedDimensionSpacePoints ?? [] as $originDimensionSpacePoint) {
                $ancestors = $this->contentGraph
                    ->getSubgraph($originDimensionSpacePoint->toDimensionSpacePoint(), VisibilityConstraints::createEmpty())
                    ->findAncestorNodes($documentAggregateId, FindAncestorNodesFilter::create());
                foreach ($ancestors as $ancestor) {
                    $ancestorIds[$ancestor->aggregateId->value] = true;
                }
            }
            $selectedAncestorCount[$documentAggregateId->value] = count(array_intersect_key($ancestorIds, $selected));
        }
        usort($documentAggregateIds, static fn (SharedModel\Node\NodeAggregateId $a, SharedModel\Node\NodeAggregateId $b): int => $ancestorsFirst
            ? $selectedAncestorCount[$a->value] <=> $selectedAncestorCount[$b->value]
            : $selectedAncestorCount[$b->value] <=> $selectedAncestorCount[$a->value]);
        return $documentAggregateIds;
    }
}
