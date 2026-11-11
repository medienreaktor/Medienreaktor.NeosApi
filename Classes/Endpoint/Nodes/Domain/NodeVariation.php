<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;

/**
 * createNodeVariant's algorithm: a node copied to another dimension space point as Neos does, with what it needs there
 * (its non-tethered ancestor for a tethered node, the ancestors not in that point yet) and, if asked, its content
 */
#[Flow\Scope('singleton')]
final readonly class NodeVariation
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
    ) {
    }

    /**
     * The node that is varied for the node, as Neos UI: a tethered node is varied with its nearest ancestor that isn't
     * tethered (the content repository varies tethered children with their parent)
     */
    public function nodeToVary(ContentGraph\Node $node): ContentGraph\Node
    {
        $source = $this->contentRepositoryRegistry->subgraphForNode($node);
        $varied = $node;
        while ($varied->classification->isTethered() && ($parent = $source->findParentNode($varied->aggregateId)) !== null && !$parent->classification->isRoot()) {
            $varied = $parent;
        }
        return $varied;
    }

    /**
     * Whether the node's aggregate has a variant of its own in the point already
     */
    public function hasVariantIn(ContentGraph\Node $node, OriginDimensionSpacePoint $targetOrigin): bool
    {
        return $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getContentGraph($node->workspaceName)
            ->findNodeAggregateById($node->aggregateId)?->occupiesDimensionSpacePoint($targetOrigin) === true;
    }

    /**
     * Creates the variant of the node (see nodeToVary()) in the target's point, as Neos'
     * NodesController::adoptNodeAndParents(): the ancestors not in that point yet first, top-down, so it has a parent
     * there. With copyContent the content below it as well. Each variant is a command of its own, nothing is rolled
     * back
     *
     * @param ContentSubgraphInterface $target the subgraph of the target point, in the node's workspace
     * @throws \Exception the content repository's, e.g. AccessDenied
     */
    public function vary(ContentGraph\Node $node, ContentSubgraphInterface $target, bool $copyContent): void
    {
        $source = $this->contentRepositoryRegistry->subgraphForNode($node);
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($target->getDimensionSpacePoint());
        $toVary = [$node];
        for ($ancestor = $source->findParentNode($node->aggregateId); $ancestor !== null && $target->findNodeById($ancestor->aggregateId) === null; $ancestor = $source->findParentNode($ancestor->aggregateId)) {
            array_unshift($toVary, $ancestor);
        }
        foreach ($toVary as $sourceNode) {
            $contentRepository->handle(CreateNodeVariant::create($node->workspaceName, $sourceNode->aggregateId, $sourceNode->originDimensionSpacePoint, $targetOrigin));
        }
        if ($copyContent) {
            $this->varyContentBelow($node, $source, $contentRepository->getContentGraph($node->workspaceName), $targetOrigin, $contentRepository);
        }
    }

    /**
     * The content below the node (not documents, not tethered nodes, which come with their parent), as Neos'
     * NodesController::createNodeVariantsForChildNodes(), leaving out the ones in the target point already
     */
    private function varyContentBelow(ContentGraph\Node $parent, ContentSubgraphInterface $source, ContentGraphInterface $contentGraph, OriginDimensionSpacePoint $targetOrigin, ContentRepository $contentRepository): void
    {
        $content = FindChildNodesFilter::create(nodeTypes: ContentGraph\Filter\NodeType\NodeTypeCriteria::fromFilterString('!' . NodeTypeNameFactory::NAME_DOCUMENT));
        foreach ($source->findChildNodes($parent->aggregateId, $content) as $child) {
            if ($child->classification->isRegular() && $contentGraph->findNodeAggregateById($child->aggregateId)?->occupiesDimensionSpacePoint($targetOrigin) !== true) {
                $contentRepository->handle(CreateNodeVariant::create($child->workspaceName, $child->aggregateId, $child->originDimensionSpacePoint, $targetOrigin));
            }
            $this->varyContentBelow($child, $source, $contentGraph, $targetOrigin, $contentRepository);
        }
    }
}
