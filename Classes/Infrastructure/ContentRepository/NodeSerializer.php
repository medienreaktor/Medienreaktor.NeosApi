<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\ContentRepository;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Reference;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

/**
 * What the API tells about a node beyond its own fields, the same for the nodes endpoint and the views
 * (Neos.Api.Node): its label, state, timestamps, properties and references as JSON-safe values
 */
#[Flow\Scope('singleton')]
final readonly class NodeSerializer
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private NodeLabelGeneratorInterface $nodeLabelGenerator,
        private ResourceManager $resourceManager,
        private PersistenceManagerInterface $persistenceManager,
    ) {
    }

    /**
     * The label as plain text: the label generator may return markup, e.g. of a text property
     */
    public function label(Node $node): string
    {
        return trim(html_entity_decode(strip_tags($this->nodeLabelGenerator->getLabel($node)), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * The properties in their serialized form, as the content repository stores them, so they are JSON-safe: e.g. a
     * date as ISO 8601 string. Assets, also in a list, are {"id": "…", "url": "…"} instead, null if the asset is gone
     *
     * @return array<string, mixed>
     */
    public function properties(PropertyCollection $properties): array
    {
        $values = [];
        foreach ($properties->serialized()->getPlainValues() as $name => $value) {
            // only arrays can be serialized assets, the others need no deserializing
            $values[$name] = is_array($value) ? $this->assetsIn($properties[$name], $value) : $value;
        }
        return $values;
    }

    /**
     * A plain property value as the content repository stores it, e.g. in an event's payload, as properties() returns
     * it: assets, also in a list, by their identifier as {"id": "…", "url": "…"}, null if the asset is gone (left out
     * of a list)
     */
    public function serializedValue(mixed $value): mixed
    {
        if (!is_array($value) || $value === []) {
            return $value;
        }
        if (self::isSerializedAsset($value)) {
            return $this->serializedAsset($value);
        }
        if (array_is_list($value) && array_filter($value, static fn (mixed $item) => !self::isSerializedAsset($item)) === []) {
            return array_values(array_filter(array_map($this->serializedAsset(...), $value)));
        }
        return $value;
    }

    /**
     * The references the node type declares, each with the referenced nodes in their order as
     * {"nodeAggregateId": "…", "properties": {…}} (the reference's properties serialized as the node's), an empty list
     * if there are none. Objects where they are empty, so they encode as {}, not []. With $referencedNode each one has
     * the referenced node as well, as "node": what the closure makes of it
     *
     * @param \Closure(Node): mixed $referencedNode
     * @return array<string, list<array{nodeAggregateId: string, properties: array<string, mixed>|\stdClass, node?: mixed}>>|\stdClass
     */
    public function references(Node $node, ?\Closure $referencedNode = null): array|\stdClass
    {
        $references = array_fill_keys(array_keys($this->nodeType($node)?->getReferences() ?? []), []);
        // in the node's subgraph, so only the referenced nodes it may read
        foreach ($this->contentRepositoryRegistry->subgraphForNode($node)->findReferences($node->aggregateId, FindReferencesFilter::create()) as $reference) {
            $references[$reference->name->value][] = $this->reference($reference) + ($referencedNode !== null ? ['node' => $referencedNode($reference->node)] : []);
        }
        return $references === [] ? new \stdClass() : $references;
    }

    /**
     * Whether the node has child nodes (of the node types, all if null), without loading them, in its subgraph: with
     * the visibility it was read with
     *
     * @param NodeTypeCriteria|string|null $nodeTypes a node type filter, e.g. "Neos.Neos:Content,Neos.Neos:ContentCollection" or "!Neos.Neos:Document"
     */
    public function hasChildren(Node $node, NodeTypeCriteria|string|null $nodeTypes = null): bool
    {
        return $this->contentRepositoryRegistry->subgraphForNode($node)->countChildNodes(
            $node->aggregateId,
            CountChildNodesFilter::create(nodeTypes: $nodeTypes),
        ) > 0;
    }

    /**
     * Whether the node itself is hidden, not just inside a hidden node
     */
    public function isHidden(Node $node): bool
    {
        return $node->tags->withoutInherited()->contain(NeosSubtreeTag::disabled());
    }

    /**
     * Whether the node is inside a hidden node, which hides it as well
     */
    public function isHiddenByAncestor(Node $node): bool
    {
        return $node->tags->onlyInherited()->contain(NeosSubtreeTag::disabled());
    }

    /**
     * Whether the node is shown in its dimension space point with the content of another one (a fallback), e.g. en_UK
     * showing en_US
     */
    public function isShineThrough(Node $node): bool
    {
        return !$node->dimensionSpacePoint->equals($node->originDimensionSpacePoint);
    }

    /**
     * When the node was created and last modified as ISO 8601, the original ones are of the live workspace
     *
     * @return array{created: string, lastModified: string|null, originalCreated: string, originalLastModified: string|null}
     */
    public function timestamps(Node $node): array
    {
        return [
            'created' => $node->timestamps->created->format(\DateTimeInterface::ATOM),
            'lastModified' => $node->timestamps->lastModified?->format(\DateTimeInterface::ATOM),
            'originalCreated' => $node->timestamps->originalCreated->format(\DateTimeInterface::ATOM),
            'originalLastModified' => $node->timestamps->originalLastModified?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{nodeAggregateId: string, properties: array<string, mixed>|\stdClass}
     */
    private function reference(Reference $reference): array
    {
        $properties = $reference->properties !== null ? $this->properties($reference->properties) : [];
        return [
            'nodeAggregateId' => $reference->node->aggregateId->value,
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];
    }

    private function nodeType(Node $node): ?NodeType
    {
        return $this->contentRepositoryRegistry->get($node->contentRepositoryId)->getNodeTypeManager()->getNodeType($node->nodeTypeName);
    }

    /**
     * @param mixed $value the deserialized property value
     * @param array<mixed> $serialized the serialized one, returned if the value holds no asset
     * @return array<mixed>|null
     */
    private function assetsIn(mixed $value, array $serialized): ?array
    {
        if ($value instanceof AssetInterface) {
            return $this->asset($value);
        }
        if (is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, static fn (mixed $item) => !$item instanceof AssetInterface) === []) {
            return array_map($this->asset(...), $value);
        }
        // a single asset that is gone deserializes to null
        return $value === null && isset($serialized['__flow_object_type']) && is_a($serialized['__flow_object_type'], AssetInterface::class, true) ? null : $serialized;
    }

    /**
     * Whether the value is an asset as the content repository's property converter serializes persisted objects
     */
    private static function isSerializedAsset(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['__flow_object_type'] ?? null)
            && is_string($value['__identifier'] ?? null)
            && is_a($value['__flow_object_type'], AssetInterface::class, true);
    }

    /**
     * @param array<mixed> $serialized
     * @return array{id: string, url: string|null}|null
     */
    private function serializedAsset(array $serialized): ?array
    {
        $asset = $this->persistenceManager->getObjectByIdentifier($serialized['__identifier'], $serialized['__flow_object_type']);
        return $asset instanceof AssetInterface ? $this->asset($asset) : null;
    }

    /**
     * @return array{id: string, url: string|null}
     */
    private function asset(AssetInterface $asset): array
    {
        $resource = $asset->getResource();
        $url = $resource !== null ? $this->resourceManager->getPublicPersistentResourceUri($resource) : null;
        return [
            'id' => $this->persistenceManager->getIdentifierByObject($asset),
            'url' => is_string($url) ? $url : null,
        ];
    }
}
