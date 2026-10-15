<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Infrastructure\ContentRepository\NodeSerializer;
use Neos\ContentRepository\Core\Projection\ContentGraph;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\NullSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A node with its properties and, if included, its references, children, variants, ancestors and whether it has
 * children
 *
 * The schema is written out: properties and references are maps, which schematic can't discover from an array, and
 * children and variants contain Node itself, see NodeList
 */
final readonly class Node implements ProvidesSchema
{
    /**
     * @param list<string> $tags
     * @param list<string> $inheritedTags
     * @param array<string, mixed> $properties
     * @param array<string, list<array{nodeAggregateId: string, properties: array<string, mixed>|\stdClass, node?: array<string, mixed>|null}>>|\stdClass|null $references
     * @param NodeList|null $children typed iterable, not NodeList, see there
     * @param NodeList|null $variants as $children
     * @param NodeList|null $ancestors as $children
     * @param bool|null $hasChildren whether the node has child nodes (of the requested node types), null unless included
     */
    public function __construct(
        public ContentRepositoryId $contentRepositoryId,
        public WorkspaceName $workspaceName,
        public DimensionSpacePoint $dimensionSpacePoint,
        public DimensionSpacePoint $originDimensionSpacePoint,
        public NodeAggregateId $nodeAggregateId,
        public NodeName|null $name,
        public NodeTypeName $nodeType,
        public string $label,
        public NodeClassification $classification,
        public bool $isHidden,
        public bool $isHiddenByAncestor,
        public bool $isShineThrough,
        public array $tags,
        public array $inheritedTags,
        public NodeTimestamps $timestamps,
        public array $properties,
        public array|\stdClass|null $references,
        public iterable|null $children,
        public iterable|null $variants,
        public iterable|null $ancestors,
        public bool|null $hasChildren,
    ) {
    }

    /**
     * @param \Closure(ContentGraph\Node): (array<string, mixed>|null)|null $referencedNode the referenced node of each
     *     reference, serialized, if they are included (include=references.node); only with $includeReferences
     */
    public static function from(
        ContentGraph\Node $node,
        NodeSerializer $nodeSerializer,
        bool $includeReferences,
        NodeList|null $children = null,
        NodeList|null $variants = null,
        NodeList|null $ancestors = null,
        bool|null $hasChildren = null,
        \Closure|null $referencedNode = null,
    ): self {
        return new self(
            ContentRepositoryId::fromString($node->contentRepositoryId->value),
            WorkspaceName::fromString($node->workspaceName->value),
            DimensionSpacePoint::from($node->dimensionSpacePoint),
            DimensionSpacePoint::from($node->originDimensionSpacePoint),
            NodeAggregateId::fromString($node->aggregateId->value),
            $node->name !== null ? NodeName::fromString($node->name->value) : null,
            NodeTypeName::fromString($node->nodeTypeName->value),
            $nodeSerializer->label($node),
            NodeClassification::from($node->classification->value),
            $nodeSerializer->isHidden($node),
            $nodeSerializer->isHiddenByAncestor($node),
            $nodeSerializer->isShineThrough($node),
            self::sorted($node->tags->withoutInherited()->toStringArray()),
            self::sorted($node->tags->onlyInherited()->toStringArray()),
            new NodeTimestamps(...$nodeSerializer->timestamps($node)),
            $nodeSerializer->properties($node->properties),
            $includeReferences ? $nodeSerializer->references($node, $referencedNode) : null,
            $children,
            $variants,
            $ancestors,
            $hasChildren,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= self::objectSchema(
            'A node in a workspace and dimension space point',
            Nullable::wrap(ObjectSchema::create(
                description: 'Only if included (e.g. include=references or children.references), else null: the references the node type declares, by name, each with the referenced nodes in their order (an empty list if there are none) as {"nodeAggregateId": "…", "properties": {…}}, the properties of the reference serialized as the node\'s. With include=references.node each one has the referenced node as well, {"nodeAggregateId": "…", "properties": {…}, "node": {…}}, as getNode returns it without include, null if the account may not read it',
                examples: [['relatedPages' => [['nodeAggregateId' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3', 'properties' => new \stdClass()]]]],
                additionalProperties: ArraySchema::create(
                    description: 'The referenced nodes of one reference, in their order',
                    items: self::referenceSchema(),
                ),
            )),
            // the very schema of NodeList, a copy with another description would be no branch the serializer knows
            Nullable::wrap(NodeList::schema()),
            Nullable::wrap(BooleanSchema::create(description: 'Only if included (include=hasChildren or children.hasChildren), else null: whether the node has child nodes, of the node types of hasChildrenOfNodeType if given, e.g. for the toggle of a tree')),
        );
    }

    /**
     * The schema of a node, with what may be included: Node's, or the one of a referenced node, which has nothing included
     */
    private static function objectSchema(string $description, Schema $references, Schema $nodeList, Schema $hasChildren): ObjectSchema
    {
        return ObjectSchema::create(
            description: $description,
            properties: ObjectProperties::create(
                contentRepositoryId: ContentRepositoryId::schema(),
                workspaceName: WorkspaceName::schema(),
                // the point the node was read in, see isShineThrough
                dimensionSpacePoint: DimensionSpacePoint::schema(),
                // the point whose content it shows, the same as dimensionSpacePoint unless isShineThrough
                originDimensionSpacePoint: DimensionSpacePoint::schema(),
                nodeAggregateId: NodeAggregateId::schema(),
                name: Nullable::wrap(NodeName::schema()),
                nodeType: NodeTypeName::schema(),
                label: StringSchema::create(description: 'The label as plain text, as the Neos backend shows it'),
                classification: NodeClassification::schema(),
                isHidden: BooleanSchema::create(description: 'Whether the node itself is hidden'),
                isHiddenByAncestor: BooleanSchema::create(description: 'Whether the node is inside a hidden node, which hides it as well'),
                isShineThrough: BooleanSchema::create(description: 'Whether the node is shown with the content of another dimension space point (a fallback), e.g. en_UK showing en_US'),
                tags: ArraySchema::create(
                    description: 'The tags set on the node itself, sorted, e.g. disabled if it is hidden (isHidden)',
                    items: SubtreeTag::schema(),
                ),
                inheritedTags: ArraySchema::create(
                    description: 'The tags the node has from its ancestors, sorted, e.g. disabled if it is inside a hidden node (isHiddenByAncestor)',
                    items: SubtreeTag::schema(),
                ),
                timestamps: NodeTimestamps::schema(),
                properties: ObjectSchema::create(
                    description: 'The properties in their serialized form, as the content repository stores them, e.g. a date as ISO 8601 string. Assets, also in a list, are {"id": "…", "url": "…"} instead, null if the asset is gone',
                    examples: [['title' => 'Home', 'image' => ['id' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3', 'url' => 'https://example.com/_Resources/Persistent/…/image.jpg']]],
                    additionalProperties: true,
                ),
                references: $references,
                children: $nodeList,
                variants: $nodeList,
                ancestors: $nodeList,
                hasChildren: $hasChildren,
            ),
            additionalProperties: false,
            required: ['contentRepositoryId', 'workspaceName', 'dimensionSpacePoint', 'originDimensionSpacePoint', 'nodeAggregateId', 'name', 'nodeType', 'label', 'classification', 'isHidden', 'isHiddenByAncestor', 'isShineThrough', 'tags', 'inheritedTags', 'timestamps', 'properties', 'references', 'children', 'variants', 'ancestors', 'hasChildren'],
        );
    }

    /**
     * A reference in references, written out: the serializer gets references as an array (NodeSerializer::references())
     * and validates it against this to tell it from null, which it can't with a $ref, so the referenced node is a copy
     * of Node with nothing included (its includes are null) instead of a $ref to Node as in NodeList
     */
    private static function referenceSchema(): Schema
    {
        return ObjectSchema::create(
            description: 'A referenced node of a reference',
            properties: ObjectProperties::create(
                nodeAggregateId: NodeAggregateId::schema(),
                properties: ObjectSchema::create(
                    description: 'The properties of the reference, serialized as the node\'s',
                    additionalProperties: true,
                ),
                node: Nullable::wrap(self::objectSchema(
                    'Only with include=references.node: the referenced node as getNode returns it without include, null if the account may not read it',
                    NullSchema::create(),
                    NullSchema::create(),
                    NullSchema::create(),
                )),
            ),
            additionalProperties: false,
            required: ['nodeAggregateId', 'properties'],
        );
    }

    /**
     * @param array<string> $tags
     * @return list<string>
     */
    private static function sorted(array $tags): array
    {
        sort($tags);
        return $tags;
    }
}
