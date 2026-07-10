<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;

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
}
