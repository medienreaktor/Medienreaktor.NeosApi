<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Service\NodeSerializer;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;

/**
 * Node reads over the security-aware content subgraph. The subgraph applies
 * the account's visibility constraints on every query: nodes the user may not
 * read do not exist in any response. Disabled ("hidden") nodes ARE included -
 * this is an editing API; pass ?visibility=frontend to preview the public view.
 */
class NodesController extends AbstractApiController
{
    #[Flow\Inject]
    protected NodeSerializer $nodeSerializer;

    #[Flow\Inject]
    protected NodeUriPathSegmentGenerator $uriPathSegmentGenerator;

    public function showAction(string $nodeAddress): string
    {
        $this->requireScope('neos.read');
        $address = $this->decodeNodeAddress($nodeAddress);
        $subgraph = $this->getSubgraph($address, $this->wantsFrontendVisibility());

        $node = $subgraph->findNodeById($address->aggregateId);
        if ($node === null) {
            $this->throwJsonStatus(404, 'node_not_found', 'The node does not exist in this subgraph or is not visible for this account.');
        }

        // Optional, like the children/descendants relations: constrains
        // hasChildren to the given node types, so a single-node refresh
        // reports the same "has X children" semantics the caller's tree/list
        // originally loaded it with, instead of silently widening to "has
        // any children" on every refetch.
        $nodeTypes = $this->getStringQueryParam('nodeTypes');

        return $this->json($this->nodeSerializer->serializeNode($node, $subgraph, $nodeTypes));
    }

    public function relationAction(string $nodeAddress, string $relation): string
    {
        $this->requireScope('neos.read');
        $address = $this->decodeNodeAddress($nodeAddress);
        $subgraph = $this->getSubgraph($address, $this->wantsFrontendVisibility());

        $nodeTypes = $this->getStringQueryParam('nodeTypes');
        $pagination = $this->getPagination();

        switch ($relation) {
            case 'children':
                $nodes = $subgraph->findChildNodes($address->aggregateId, FindChildNodesFilter::create(nodeTypes: $nodeTypes, pagination: $pagination));
                break;
            case 'descendants':
                // ?search= narrows by fulltext over the property values (the CR's
                // SearchTerm semantics) - what reference editors and pickers use
                // for search-as-you-type. Search responses additionally carry a
                // document breadcrumb per node, so same-named results stay
                // distinguishable ("Home > Products > News" vs "Home > Blog > News").
                $search = $this->getStringQueryParam('search');
                $nodes = $subgraph->findDescendantNodes($address->aggregateId, FindDescendantNodesFilter::create(nodeTypes: $nodeTypes, searchTerm: $search, pagination: $pagination));
                if ($search !== null) {
                    $items = [];
                    foreach ($nodes as $node) {
                        $items[] = $this->nodeSerializer->serializeNode($node, $subgraph, $nodeTypes)
                            + ['breadcrumb' => $this->nodeSerializer->breadcrumb($node, $subgraph)];
                    }

                    return $this->json(['nodes' => $items]);
                }
                break;
            case 'ancestors':
                $nodes = $subgraph->findAncestorNodes($address->aggregateId, FindAncestorNodesFilter::create(nodeTypes: $nodeTypes));
                break;
            case 'parent':
                $parent = $subgraph->findParentNode($address->aggregateId);
                if ($parent === null) {
                    $this->throwJsonStatus(404, 'node_not_found', 'The node has no visible parent in this subgraph.');
                }

                return $this->json($this->nodeSerializer->serializeNode($parent, $subgraph, $nodeTypes));
            case 'allowed-child-node-types':
                // Which node types the content model permits as children of
                // this node - a drag-and-drop / creation client validates a
                // target against this instead of shipping a constraint engine.
                $node = $subgraph->findNodeById($address->aggregateId);
                if ($node === null) {
                    $this->throwJsonStatus(404, 'node_not_found', 'The node does not exist in this subgraph or is not visible for this account.');
                }

                return $this->json(['nodeTypes' => $this->allowedChildNodeTypeNames($node, $subgraph)]);
            case 'variants':
                // Aggregate-level view: in which dimension space points does
                // this node exist? "occupied" = own variants (origins),
                // "covered" = additionally reachable via specialization
                // shine-through. A point outside "covered" needs a
                // CreateNodeVariant command before the node appears there.
                if ($subgraph->findNodeById($address->aggregateId) === null) {
                    $this->throwJsonStatus(404, 'node_not_found', 'The node does not exist in this subgraph or is not visible for this account.');
                }
                $nodeAggregate = $this->getContentRepository()->getContentGraph($address->workspaceName)->findNodeAggregateById($address->aggregateId);
                if ($nodeAggregate === null) {
                    $this->throwJsonStatus(404, 'node_not_found', 'The node aggregate does not exist in this workspace.');
                }

                return $this->json([
                    'occupiedDimensionSpacePoints' => array_map(
                        static fn (OriginDimensionSpacePoint $point) => $point->coordinates,
                        array_values(iterator_to_array($nodeAggregate->occupiedDimensionSpacePoints))
                    ),
                    'coveredDimensionSpacePoints' => array_map(
                        static fn (DimensionSpacePoint $point) => $point->coordinates,
                        array_values(iterator_to_array($nodeAggregate->coveredDimensionSpacePoints))
                    ),
                ]);
            case 'references':
                $references = $subgraph->findReferences($address->aggregateId, FindReferencesFilter::create());
                $items = [];
                foreach ($references as $reference) {
                    $items[] = [
                        'referenceName' => $reference->name->value,
                        'node' => $this->nodeSerializer->serializeNode($reference->node, $subgraph),
                        'properties' => $reference->properties === null
                            ? null
                            : json_decode(json_encode($reference->properties->serialized(), JSON_THROW_ON_ERROR), true),
                    ];
                }

                return $this->json(['references' => $items]);
            case 'uri-path-segment':
                // Build a URL path segment from ?text= (typically the current
                // title), or from the node's label when no text is given -
                // applying the same language-aware transliteration the classic
                // UI's uriPathSegment "sync" button relies on. A read-only
                // computation, hence a GET relation like the others.
                $node = $subgraph->findNodeById($address->aggregateId);
                if ($node === null) {
                    $this->throwJsonStatus(404, 'node_not_found', 'The node does not exist in this subgraph or is not visible for this account.');
                }

                return $this->json(['slug' => $this->uriPathSegmentGenerator->generateUriPathSegment($node, $this->getStringQueryParam('text'))]);
            default:
                $this->throwJsonStatus(404, 'unknown_relation', sprintf('Unknown relation "%s". Supported: children, descendants, ancestors, parent, references, variants, allowed-child-node-types, uri-path-segment.', $relation));
        }

        return $this->json(['nodes' => $this->nodeSerializer->serializeNodes($nodes, $subgraph, $nodeTypes)]);
    }

    /**
     * Names of the non-abstract node types the content model allows as
     * children of the given node - what a client needs to validate a move or
     * a creation against the model without a constraint engine of its own.
     * Mirrors the classic UI's semantics: for tethered nodes (autocreated
     * collections) the constraints of the declaring parent's tethered-child
     * definition apply, for regular nodes the node type's own constraints.
     * Unlike the creation menu no ui.group filter is applied - a move is not
     * limited to user-creatable types.
     *
     * @return array<int, string>
     */
    private function allowedChildNodeTypeNames(Node $node, ContentSubgraphInterface $subgraph): array
    {
        $nodeTypeManager = $this->getContentRepository()->getNodeTypeManager();
        $nodeType = $nodeTypeManager->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return [];
        }

        $tetheredParentNodeTypeName = null;
        if ($node->classification === NodeAggregateClassification::CLASSIFICATION_TETHERED && $node->name !== null) {
            $tetheredParentNodeTypeName = $subgraph->findParentNode($node->aggregateId)?->nodeTypeName;
        }

        $allowed = [];
        foreach ($nodeTypeManager->getNodeTypes(false) as $candidate) {
            $isAllowed = $tetheredParentNodeTypeName !== null
                ? $nodeTypeManager->isNodeTypeAllowedAsChildToTetheredNode($tetheredParentNodeTypeName, $node->name, $candidate->name)
                : $nodeType->allowsChildNodeType($candidate);
            if ($isAllowed) {
                $allowed[] = $candidate->name->value;
            }
        }

        return $allowed;
    }

    private function wantsFrontendVisibility(): bool
    {
        return $this->getStringQueryParam('visibility') === 'frontend';
    }

    private function getStringQueryParam(string $name): ?string
    {
        $value = $this->request->getHttpRequest()->getQueryParams()[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function getPagination(): ?Pagination
    {
        $params = $this->request->getHttpRequest()->getQueryParams();
        if (!isset($params['limit'])) {
            return null;
        }

        return Pagination::fromLimitAndOffset(max(1, (int)$params['limit']), max(0, (int)($params['offset'] ?? 0)));
    }
}
