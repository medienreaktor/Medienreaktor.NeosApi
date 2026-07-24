<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;

/**
 * Serializes content graph nodes into the API's JSON representation.
 *
 * Property values are emitted in their SERIALIZED form ({value, type} pairs) -
 * that is JSON-safe by definition and round-trips losslessly with the
 * command endpoints.
 */
#[Flow\Scope('singleton')]
class NodeSerializer
{
    /**
     * Resolves the canonical Neos node label (the same one the classic UI tree
     * shows): the node type's `label` Eel expression, a custom generatorClass,
     * or the nodeType-name/nodeName fallback - including tethered-collection
     * labels. Binds to DelegatingNodeLabelRenderer by default.
     */
    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

    /**
     * The $subgraph is used to determine `hasChildren`, evaluated against the
     * node's visible children. When $childrenNodeTypes is given (typically the
     * nodeTypes filter of the surrounding request), it constrains that check,
     * so e.g. a document listing reports whether each document has DOCUMENT
     * children - which is what tree UIs need to render expand affordances.
     *
     * @return array<string, mixed>
     */
    public function serializeNode(Node $node, ContentSubgraphInterface $subgraph, ?string $childrenNodeTypes = null): array
    {
        return [
            'address' => NodeAddressCodec::encode(\Neos\ContentRepository\Core\SharedModel\Node\NodeAddress::fromNode($node)),
            'aggregateId' => $node->aggregateId->value,
            'nodeType' => $node->nodeTypeName->value,
            'name' => $node->name?->value,
            'label' => $this->plainTextLabel($this->nodeLabelGenerator->getLabel($node)),
            'classification' => $node->classification->value,
            'hasChildren' => $subgraph->countChildNodes($node->aggregateId, CountChildNodesFilter::create(nodeTypes: $childrenNodeTypes)) > 0,
            'workspace' => $node->workspaceName->value,
            'dimensionSpacePoint' => $node->dimensionSpacePoint->coordinates,
            'originDimensionSpacePoint' => $node->originDimensionSpacePoint->coordinates,
            'properties' => json_decode(json_encode($node->properties->serialized(), JSON_THROW_ON_ERROR), true),
            'tags' => [
                'all' => $node->tags->map(static fn (SubtreeTag $tag) => $tag->value),
                'inherited' => $node->tags->onlyInherited()->map(static fn (SubtreeTag $tag) => $tag->value),
            ],
            'timestamps' => [
                'created' => $node->timestamps->created->format(\DateTimeInterface::ATOM),
                'originalCreated' => $node->timestamps->originalCreated->format(\DateTimeInterface::ATOM),
                'lastModified' => $node->timestamps->lastModified?->format(\DateTimeInterface::ATOM),
                'originalLastModified' => $node->timestamps->originalLastModified?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    /**
     * The canonical human-readable label of a node, as plain text - what every
     * label field the API emits contains. Public: the workspace history/diff
     * serialization names nodes with exactly this label.
     */
    public function label(Node $node): string
    {
        return $this->plainTextLabel($this->nodeLabelGenerator->getLabel($node));
    }

    /**
     * The label generator returns display text that may carry HTML entities
     * (e.g. a title "Tom &amp; Jerry") or stray markup. The client renders the
     * label as plain text, so decode entities to their glyphs and strip any
     * tags here - mirroring Neos' own NodeLabelToken sanitisation - so "&amp;"
     * shows as "&" instead of literally.
     */
    private function plainTextLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = strip_tags($label);

        return trim($label);
    }

    /**
     * @param iterable<Node> $nodes
     * @return array<int, array<string, mixed>>
     */
    public function serializeNodes(iterable $nodes, ContentSubgraphInterface $subgraph, ?string $childrenNodeTypes = null): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $result[] = $this->serializeNode($node, $subgraph, $childrenNodeTypes);
        }

        return $result;
    }

    /**
     * Document labels from the site down to (and including) the node - the
     * disambiguation line a search result shows under its label ("where does
     * this page live?"). Same shape as the workspace document-changes
     * breadcrumb.
     *
     * @return array<int, string>
     */
    public function breadcrumb(Node $node, ContentSubgraphInterface $subgraph): array
    {
        $ancestors = $subgraph->findAncestorNodes(
            $node->aggregateId,
            FindAncestorNodesFilter::create(nodeTypes: 'Neos.Neos:Document')
        );
        $breadcrumb = [];
        // findAncestorNodes yields nearest-first; reverse for site-to-here order.
        foreach (array_reverse(iterator_to_array($ancestors)) as $ancestor) {
            $breadcrumb[] = $this->plainTextLabel($this->nodeLabelGenerator->getLabel($ancestor));
        }
        $breadcrumb[] = $this->plainTextLabel($this->nodeLabelGenerator->getLabel($node));

        return $breadcrumb;
    }
}
