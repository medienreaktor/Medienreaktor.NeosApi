<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Domain;

use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Endpoint\Nodes\Schema\NodeList;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\Api\Shared\Parameter\IncludePaths;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;

/**
 * The nodes listWorkspaceChanges and listWorkspaceEvents include (include=node, document and their .ancestors), as
 * getNode returns them, for one request
 *
 * A node is read in the workspace as the account sees it with deleted nodes (includeRemoved), else in the base
 * workspace (a node removed for good in the workspace), in the dimension space point given or, without one, in one its
 * aggregate covers. Its ancestors are read where it was found. Null where there is none the account may read.
 * Subgraphs and nodes are cached, most changes and events share a few documents
 */
#[Flow\Proxy(false)]
final class IncludedNodes
{
    /**
     * The include paths these are, to check an include parameter against
     */
    public const array PATHS = ['node', 'node.ancestors', 'document', 'document.ancestors'];

    /**
     * @var array<string, ContentSubgraphInterface|null> by base or workspace and dimension space point hash
     */
    private array $subgraphs = [];

    /**
     * @var array<string, ContentGraph\Node|null> by kind, node aggregate id and dimension space point hash
     */
    private array $nodes = [];

    /**
     * @var array<string, Node> by node, where it was read, and whether with ancestors
     */
    private array $serialized = [];

    private function __construct(
        private readonly ReadableWorkspace $workspace,
        private readonly ContentSubgraphs $contentSubgraphs,
        private readonly NodeSerializer $nodeSerializer,
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        public readonly bool $node,
        private readonly bool $nodeAncestors,
        public readonly bool $document,
        private readonly bool $documentAncestors,
    ) {
    }

    public static function for(IncludePaths $include, ReadableWorkspace $workspace, ContentSubgraphs $contentSubgraphs, NodeSerializer $nodeSerializer, ContentRepositoryRegistry $contentRepositoryRegistry): self
    {
        return new self(
            $workspace,
            $contentSubgraphs,
            $nodeSerializer,
            $contentRepositoryRegistry,
            $include->includes('node'),
            $include->includes('node.ancestors'),
            $include->includes('document'),
            $include->includes('document.ancestors'),
        );
    }

    /**
     * The node itself if include=node
     */
    public function nodeOf(NodeAggregateId $nodeAggregateId, DimensionSpacePoint|null $dimensionSpacePoint): Node|null
    {
        if (!$this->node) {
            return null;
        }
        $node = $this->find($nodeAggregateId, $dimensionSpacePoint);
        return $node !== null ? $this->serialize($node, $this->nodeAncestors) : null;
    }

    /**
     * A document known by its id (a change's documentAggregateId) if include=document
     */
    public function documentById(NodeAggregateId|null $documentAggregateId, DimensionSpacePoint|null $dimensionSpacePoint): Node|null
    {
        if (!$this->document || $documentAggregateId === null) {
            return null;
        }
        $document = $this->find($documentAggregateId, $dimensionSpacePoint);
        return $document !== null ? $this->serialize($document, $this->documentAncestors) : null;
    }

    /**
     * The closest document of the node (the node itself if it is one) if include=document, where the node was found
     */
    public function documentOf(NodeAggregateId $nodeAggregateId, DimensionSpacePoint|null $dimensionSpacePoint): Node|null
    {
        if (!$this->document) {
            return null;
        }
        $node = $this->find($nodeAggregateId, $dimensionSpacePoint);
        if ($node === null) {
            return null;
        }
        $key = 'document|' . $node->aggregateId->value . '|' . $node->workspaceName->value . '|' . $node->dimensionSpacePoint->hash;
        if (!array_key_exists($key, $this->nodes)) {
            $this->nodes[$key] = $this->contentRepositoryRegistry->subgraphForNode($node)
                ->findClosestNode($node->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT));
        }
        $document = $this->nodes[$key];
        return $document !== null ? $this->serialize($document, $this->documentAncestors) : null;
    }

    private function find(NodeAggregateId $nodeAggregateId, DimensionSpacePoint|null $dimensionSpacePoint): ContentGraph\Node|null
    {
        $dimensionSpacePoint ??= $this->anyCoveredDimensionSpacePoint($nodeAggregateId);
        if ($dimensionSpacePoint === null) {
            return null;
        }
        $key = 'node|' . $nodeAggregateId->value . '|' . $dimensionSpacePoint->hash;
        if (!array_key_exists($key, $this->nodes)) {
            $this->nodes[$key] = $this->subgraph($dimensionSpacePoint, false)?->findNodeById($nodeAggregateId)
                ?? $this->subgraph($dimensionSpacePoint, true)?->findNodeById($nodeAggregateId);
        }
        return $this->nodes[$key];
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

    /**
     * The subgraph as the account sees it with deleted nodes, null for no base or one it may not read
     */
    private function subgraph(DimensionSpacePoint $dimensionSpacePoint, bool $base): ContentSubgraphInterface|null
    {
        $key = ($base ? 'base|' : 'workspace|') . $dimensionSpacePoint->hash;
        if (!array_key_exists($key, $this->subgraphs)) {
            $workspaceName = $base ? $this->workspace->workspace->baseWorkspaceName : $this->workspace->workspace->workspaceName;
            $this->subgraphs[$key] = $workspaceName !== null
                ? $this->contentSubgraphs->findIn($this->workspace->contentRepository, $workspaceName, $dimensionSpacePoint, excludeDisabled: false, includeRemoved: true)
                : null;
        }
        return $this->subgraphs[$key];
    }

    private function serialize(ContentGraph\Node $node, bool $withAncestors): Node
    {
        $key = $node->aggregateId->value . '|' . $node->workspaceName->value . '|' . $node->dimensionSpacePoint->hash . '|' . ($withAncestors ? 'ancestors' : '');
        return $this->serialized[$key] ??= Node::from($node, $this->nodeSerializer, false, ancestors: $withAncestors ? $this->ancestors($node) : null);
    }

    /**
     * The nodes above the node, nearest first, without the root, as getNode includes them
     */
    private function ancestors(ContentGraph\Node $node): NodeList
    {
        $ancestors = $this->contentRepositoryRegistry->subgraphForNode($node)->findAncestorNodes($node->aggregateId, FindAncestorNodesFilter::create());
        return new NodeList(...array_map(
            fn (ContentGraph\Node $ancestor) => Node::from($ancestor, $this->nodeSerializer, false),
            array_values(array_filter(iterator_to_array($ancestors, false), static fn (ContentGraph\Node $ancestor) => !$ancestor->classification->isRoot())),
        ));
    }
}
