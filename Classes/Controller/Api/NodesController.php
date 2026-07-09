<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Controller\Api;

use Medienreaktor\NeosApi\Service\NodeSerializer;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;

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

    public function showAction(string $nodeAddress): string
    {
        $this->requireScope('neos.read');
        $address = $this->decodeNodeAddress($nodeAddress);
        $subgraph = $this->getSubgraph($address, $this->wantsFrontendVisibility());

        $node = $subgraph->findNodeById($address->aggregateId);
        if ($node === null) {
            $this->throwJsonStatus(404, 'node_not_found', 'The node does not exist in this subgraph or is not visible for this account.');
        }

        return $this->json($this->nodeSerializer->serializeNode($node));
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
                $nodes = $subgraph->findDescendantNodes($address->aggregateId, FindDescendantNodesFilter::create(nodeTypes: $nodeTypes, pagination: $pagination));
                break;
            case 'ancestors':
                $nodes = $subgraph->findAncestorNodes($address->aggregateId, FindAncestorNodesFilter::create(nodeTypes: $nodeTypes));
                break;
            case 'parent':
                $parent = $subgraph->findParentNode($address->aggregateId);
                if ($parent === null) {
                    $this->throwJsonStatus(404, 'node_not_found', 'The node has no visible parent in this subgraph.');
                }

                return $this->json($this->nodeSerializer->serializeNode($parent));
            case 'references':
                $references = $subgraph->findReferences($address->aggregateId, FindReferencesFilter::create());
                $items = [];
                foreach ($references as $reference) {
                    $items[] = [
                        'referenceName' => $reference->name->value,
                        'node' => $this->nodeSerializer->serializeNode($reference->node),
                        'properties' => $reference->properties === null
                            ? null
                            : json_decode(json_encode($reference->properties->serialized(), JSON_THROW_ON_ERROR), true),
                    ];
                }

                return $this->json(['references' => $items]);
            default:
                $this->throwJsonStatus(404, 'unknown_relation', sprintf('Unknown relation "%s". Supported: children, descendants, ancestors, parent, references.', $relation));
        }

        return $this->json(['nodes' => $this->nodeSerializer->serializeNodes($nodes)]);
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
