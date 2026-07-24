<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;

/**
 * Per-request read context for one workspace: caches every repeated lookup the
 * workspace resources fan out over their changes/events - subgraphs per
 * dimension (workspace and base side), node resolution with base fallback,
 * closest-document/site ancestors, and node-type UI configuration. The change
 * listings issue these lookups once per pending change/event; without the
 * caches a workspace with hundreds of changes pays hundreds of identical
 * graph queries per request.
 *
 * Deliberately a plain object created per action (not an injected singleton):
 * all cached state is scoped to one workspace and one request.
 */
final class WorkspaceReadContext
{
    /** @var array<string, ContentSubgraphInterface> */
    private array $subgraphs = [];

    /** @var array<string, ContentSubgraphInterface> */
    private array $baseSubgraphs = [];

    /** Resolved nodes (workspace, base fallback): "id|dimHash" => [?Node, ?subgraph] */
    /** @var array<string, array{?Node, ?ContentSubgraphInterface}> */
    private array $resolvedNodes = [];

    /** Closest ancestors: "side|nodeTypes|id|dimHash" => ?Node */
    /** @var array<string, ?Node> */
    private array $closestNodes = [];

    /** Full node-type configuration (an expensive deep merge) per type name. */
    /** @var array<string, array<string, mixed>|null> */
    private array $nodeTypeConfigurations = [];

    public function __construct(
        public readonly ContentRepository $contentRepository,
        public readonly Workspace $workspace,
        private readonly NodeSerializer $nodeSerializer,
    ) {
    }

    public function subgraph(DimensionSpacePoint $dimensionSpacePoint): ContentSubgraphInterface
    {
        return $this->subgraphs[$dimensionSpacePoint->hash] ??= $this->contentRepository->getContentSubgraph(
            $this->workspace->workspaceName,
            $dimensionSpacePoint
        );
    }

    /** The base workspace's subgraph, or null for a root workspace. */
    public function baseSubgraph(DimensionSpacePoint $dimensionSpacePoint): ?ContentSubgraphInterface
    {
        if ($this->workspace->baseWorkspaceName === null) {
            return null;
        }

        return $this->baseSubgraphs[$dimensionSpacePoint->hash] ??= $this->contentRepository->getContentSubgraph(
            $this->workspace->baseWorkspaceName,
            $dimensionSpacePoint
        );
    }

    /**
     * A node by id in the workspace's subgraph, falling back to the base
     * workspace for nodes removed in the workspace (so a deletion still shows
     * what it deleted). Returns the node plus the subgraph it resolved in.
     *
     * @param array<string, string>|null $coordinates
     * @return array{?Node, ?ContentSubgraphInterface}
     */
    public function resolveNode(?string $nodeId, ?array $coordinates): array
    {
        if (!is_string($nodeId) || !is_array($coordinates)) {
            return [null, null];
        }
        $dimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);
        $cacheKey = $nodeId . '|' . $dimensionSpacePoint->hash;
        if (array_key_exists($cacheKey, $this->resolvedNodes)) {
            return $this->resolvedNodes[$cacheKey];
        }

        $aggregateId = NodeAggregateId::fromString($nodeId);
        $subgraph = $this->subgraph($dimensionSpacePoint);
        $node = $subgraph->findNodeById($aggregateId);
        if ($node !== null) {
            return $this->resolvedNodes[$cacheKey] = [$node, $subgraph];
        }
        $baseSubgraph = $this->baseSubgraph($dimensionSpacePoint);
        if ($baseSubgraph !== null) {
            $node = $baseSubgraph->findNodeById($aggregateId);
            if ($node !== null) {
                return $this->resolvedNodes[$cacheKey] = [$node, $baseSubgraph];
            }
        }

        return $this->resolvedNodes[$cacheKey] = [null, null];
    }

    /**
     * The closest ancestor (or self) of the given node types, in the workspace
     * or the base side. Cached: change listings ask this once per pending
     * change, and most changes share the same few documents.
     */
    public function closestNode(NodeAggregateId $nodeId, DimensionSpacePoint $dimensionSpacePoint, string $nodeTypes, bool $inBase = false): ?Node
    {
        $subgraph = $inBase ? $this->baseSubgraph($dimensionSpacePoint) : $this->subgraph($dimensionSpacePoint);
        if ($subgraph === null) {
            return null;
        }
        $cacheKey = ($inBase ? 'base' : 'ws') . '|' . $nodeTypes . '|' . $nodeId->value . '|' . $dimensionSpacePoint->hash;
        if (!array_key_exists($cacheKey, $this->closestNodes)) {
            $this->closestNodes[$cacheKey] = $subgraph->findClosestNode(
                $nodeId,
                FindClosestNodeFilter::create(nodeTypes: $nodeTypes)
            );
        }

        return $this->closestNodes[$cacheKey];
    }

    /**
     * The containing document and site of a changed node, resolved in the
     * workspace with the base-workspace fallback for removed nodes - the
     * shared resolution of the changes, document-changes and review resources.
     * A node removed in this workspace is gone from its subgraph, but still
     * exists in the base; resolving there keeps a deletion attributed to its
     * document and site (a base-resolved document is display-only, not
     * navigable - hence the inWorkspace flag).
     *
     * @return array{document: ?Node, site: ?Node, inWorkspace: bool}
     */
    public function closestDocumentAndSite(NodeAggregateId $nodeId, DimensionSpacePoint $dimensionSpacePoint): array
    {
        $document = $this->closestNode($nodeId, $dimensionSpacePoint, 'Neos.Neos:Document');
        $site = $this->closestNode($nodeId, $dimensionSpacePoint, 'Neos.Neos:Site');
        $inWorkspace = $document !== null;
        if ($document === null) {
            $document = $this->closestNode($nodeId, $dimensionSpacePoint, 'Neos.Neos:Document', inBase: true);
            $site ??= $this->closestNode($nodeId, $dimensionSpacePoint, 'Neos.Neos:Site', inBase: true);
        }

        return ['document' => $document, 'site' => $site, 'inWorkspace' => $inWorkspace];
    }

    /** The canonical plain-text node label (see NodeSerializer::label()). */
    public function label(Node $node): string
    {
        return $this->nodeSerializer->label($node);
    }

    /**
     * Node ids as {id, label} pairs a human can read, resolved in the
     * workspace (falling back to the base) in the given dimension.
     *
     * @param list<string> $nodeIds
     * @param array<string, string>|null $coordinates
     * @return list<array{id: string, label: ?string}>
     */
    public function describeNodes(array $nodeIds, ?array $coordinates): array
    {
        $described = [];
        foreach ($nodeIds as $nodeId) {
            [$node] = $this->resolveNode($nodeId, $coordinates);
            $described[] = [
                'id' => $nodeId,
                'label' => $node !== null ? $this->label($node) : null,
            ];
        }

        return $described;
    }

    /**
     * The node type's full configuration - a deep merge expensive enough to
     * remember per type name; label and icon lookups hit it per property row
     * and per document.
     *
     * @return array<string, mixed>|null
     */
    public function nodeTypeConfiguration(NodeTypeName $nodeTypeName): ?array
    {
        if (!array_key_exists($nodeTypeName->value, $this->nodeTypeConfigurations)) {
            $this->nodeTypeConfigurations[$nodeTypeName->value] = $this->contentRepository
                ->getNodeTypeManager()->getNodeType($nodeTypeName)?->getFullConfiguration();
        }

        return $this->nodeTypeConfigurations[$nodeTypeName->value];
    }

    /** The node type's configured UI icon. */
    public function icon(NodeTypeName $nodeTypeName): ?string
    {
        return $this->nodeTypeConfiguration($nodeTypeName)['ui']['icon'] ?? null;
    }

    /**
     * The configured human label of a property or reference from the node's
     * type - possibly an XLIFF shorthand the client translates, like every
     * node-type label the API emits.
     */
    public function propertyLabel(?Node $node, string $name, string $section): ?string
    {
        if ($node === null) {
            return null;
        }
        $label = $this->nodeTypeConfiguration($node->nodeTypeName)[$section][$name]['ui']['label'] ?? null;

        return is_string($label) ? $label : null;
    }
}
