<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;

/**
 * Where the changes of a workspace are: the closest document and site of a changed node, for one request. Looked up
 * without visibility constraints, so hidden and (soft) removed nodes are found, in the workspace, else in its base
 * workspace for a node removed for good in the workspace. Subgraphs and lookups are cached, most changes share a few
 * documents
 */
#[Flow\Proxy(false)]
final class ChangeLocator
{
    /**
     * @var array<string, ContentSubgraphInterface> by base or workspace and dimension space point hash
     */
    private array $subgraphs = [];

    /**
     * @var array<string, array{document: NodeAggregateId|null, site: NodeAggregateId|null}> by node aggregate id and origin
     */
    private array $locations = [];

    /**
     * @var array<string, NodeTypeName|null> by node aggregate id
     */
    private array $nodeTypeNames = [];

    public function __construct(
        private readonly ReadableWorkspace $workspace,
    ) {
    }

    /**
     * The closest document (the node itself if it is one) and site of the node, in its origin dimension space point,
     * or for a change to the whole aggregate in one it covers
     *
     * @return array{document: NodeAggregateId|null, site: NodeAggregateId|null}
     */
    public function locate(NodeAggregateId $nodeAggregateId, OriginDimensionSpacePoint|null $origin): array
    {
        return $this->locations[$nodeAggregateId->value . '|' . ($origin?->hash ?? '')] ??= (function () use ($nodeAggregateId, $origin): array {
            $dimensionSpacePoint = $origin?->toDimensionSpacePoint() ?? $this->anyCoveredDimensionSpacePoint($nodeAggregateId);
            if ($dimensionSpacePoint === null) {
                return ['document' => null, 'site' => null];
            }
            $document = $this->closest($nodeAggregateId, $dimensionSpacePoint, NodeTypeNameFactory::NAME_DOCUMENT, false);
            $site = $this->closest($nodeAggregateId, $dimensionSpacePoint, NodeTypeNameFactory::NAME_SITE, false);
            // removed for good in the workspace (RemoveNodeAggregate, not deleteNode): it's still in the base workspace
            if ($document === null) {
                $document = $this->closest($nodeAggregateId, $dimensionSpacePoint, NodeTypeNameFactory::NAME_DOCUMENT, true);
                $site ??= $this->closest($nodeAggregateId, $dimensionSpacePoint, NodeTypeNameFactory::NAME_SITE, true);
            }
            return ['document' => $document, 'site' => $site];
        })();
    }

    /**
     * The node type of the aggregate in the workspace, else in its base workspace (removed for good in the workspace)
     */
    public function nodeTypeName(NodeAggregateId $nodeAggregateId): NodeTypeName|null
    {
        if (!array_key_exists($nodeAggregateId->value, $this->nodeTypeNames)) {
            $this->nodeTypeNames[$nodeAggregateId->value] = $this->workspace->contentGraph()->findNodeAggregateById($nodeAggregateId)?->nodeTypeName
                ?? $this->workspace->contentGraph(base: true)?->findNodeAggregateById($nodeAggregateId)?->nodeTypeName;
        }
        return $this->nodeTypeNames[$nodeAggregateId->value];
    }

    private function anyCoveredDimensionSpacePoint(NodeAggregateId $nodeAggregateId): DimensionSpacePoint|null
    {
        foreach ([$this->workspace->contentGraph(), $this->workspace->contentGraph(base: true)] as $contentGraph) {
            foreach ($contentGraph?->findNodeAggregateById($nodeAggregateId)?->coveredDimensionSpacePoints ?? [] as $dimensionSpacePoint) {
                return $dimensionSpacePoint;
            }
        }
        return null;
    }

    private function closest(NodeAggregateId $nodeAggregateId, DimensionSpacePoint $dimensionSpacePoint, string $nodeType, bool $inBase): NodeAggregateId|null
    {
        $contentGraph = $this->workspace->contentGraph($inBase);
        if ($contentGraph === null) {
            return null;
        }
        $subgraph = $this->subgraphs[($inBase ? 'base|' : 'workspace|') . $dimensionSpacePoint->hash] ??= $contentGraph->getSubgraph($dimensionSpacePoint, VisibilityConstraints::createEmpty());
        return $subgraph->findClosestNode($nodeAggregateId, FindClosestNodeFilter::create(nodeTypes: $nodeType))?->aggregateId;
    }
}
