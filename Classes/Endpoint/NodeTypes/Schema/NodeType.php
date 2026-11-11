<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\Api\Infrastructure\ContentRepository\NodeTypeUi;
use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core;
use Neos\JsonSchema\AnyOfSchema;
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\NullSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A node type with what trees and creation UIs need and, if requested, its properties, references, whole
 * configuration and the node types allowed below its nodes
 *
 * The schema is written out: position is an integer or a string, and the configuration a map, which schematic can't
 * discover
 */
final readonly class NodeType implements ProvidesSchema
{
    /**
     * The keys whose string values in the configuration are labels, as the Neos UI translates them: ui.label,
     * ui.help.message, editorOptions.placeholder, and the labels of inspector tabs, groups and views, select box values
     * and creation dialog elements
     */
    private const array LABEL_KEYS = ['label', 'message', 'placeholder'];

    /**
     * @param array<string, mixed>|\stdClass|null $configuration
     * @param NodeTypeNameList|null $allowedChildNodeTypes the node types nodes of this type may have as children
     *     (constraints.nodeTypes), neither abstract nor root ones. A tethered node, e.g. a page's main content
     *     collection, follows its parent's tetheredChildNodes instead
     * @param NodeTypeTetheredChildNodeList|null $tetheredChildNodes the child nodes nodes of this type are created with
     *     (childNodes), with the node types allowed below each
     */
    public function __construct(
        public NodeTypeName $name,
        public string $label,
        public string|null $icon,
        public bool $isAbstract,
        public bool $isFinal,
        public NodeTypeNameList $superTypes,
        public string|null $group,
        public int|string|null $position,
        public NodeTypePropertyList|null $properties,
        public NodeTypeReferenceList|null $references,
        public array|\stdClass|null $configuration,
        public NodeTypeNameList|null $allowedChildNodeTypes,
        public NodeTypeTetheredChildNodeList|null $tetheredChildNodes,
    ) {
    }

    /**
     * @param list<Core\NodeType\NodeType> $childNodeTypeCandidates childNodeTypeCandidates() of the node type manager,
     *     computed once for all node types; only needed with the allowed child node types or tethered child nodes
     */
    public static function from(
        Core\NodeType\NodeType $nodeType,
        Labels $labels,
        NodeTypeUi $nodeTypeUi,
        Core\NodeType\NodeTypeManager $nodeTypeManager,
        array $childNodeTypeCandidates,
        bool $withProperties,
        bool $withReferences,
        bool $withConfiguration,
        bool $withAllowedChildNodeTypes,
        bool $withTetheredChildNodes,
    ): self {
        $group = $nodeType->getConfiguration('ui.group');
        $position = $nodeType->getConfiguration('ui.position');
        $configuration = $withConfiguration ? self::translateLabels($nodeType->getFullConfiguration(), $labels) : null;
        return new self(
            NodeTypeName::fromString($nodeType->name->value),
            $nodeTypeUi->label($nodeType, $labels),
            $nodeTypeUi->icon($nodeType),
            $nodeType->isAbstract(),
            $nodeType->isFinal(),
            new NodeTypeNameList(...array_map(
                static fn (string $name) => NodeTypeName::fromString($name),
                array_keys($nodeType->getDeclaredSuperTypes()),
            )),
            is_string($group) && $group !== '' ? $group : null,
            is_int($position) || is_string($position) ? $position : null,
            $withProperties ? self::properties($nodeType, $labels) : null,
            $withReferences ? self::references($nodeType, $labels) : null,
            // an object where it is empty: the serializer can't tell an empty map from an empty list
            $configuration === [] ? new \stdClass() : $configuration,
            $withAllowedChildNodeTypes ? self::allowedChildNodeTypes($nodeType, $childNodeTypeCandidates) : null,
            $withTetheredChildNodes ? self::tetheredChildNodes($nodeType, $nodeTypeManager, $childNodeTypeCandidates) : null,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A node type of a content repository',
            properties: ObjectProperties::create(
                name: NodeTypeName::schema(),
                label: StringSchema::create(description: 'The label (ui.label) in the requested language, the name if it has none'),
                icon: Nullable::wrap(StringSchema::create(
                    description: 'The icon (ui.icon) as Font Awesome classes, legacy names like "icon-file" or "file" mapped as the Neos UI does',
                    examples: ['fas fa-file'],
                )),
                isAbstract: BooleanSchema::create(description: 'Whether the node type only serves as a super type, nodes can\'t have it'),
                isFinal: BooleanSchema::create(description: 'Whether no other node type may inherit from it'),
                superTypes: NodeTypeNameList::schema(),
                group: Nullable::wrap(StringSchema::create(
                    description: 'The group (ui.group) the Neos UI offers it in for creation, a key of the Neos.Neos.nodeTypes.groups settings. Node types without a group aren\'t offered',
                    examples: ['general'],
                )),
                position: AnyOfSchema::create(
                    IntegerSchema::create(description: 'The position (ui.position) within its group as a number'),
                    StringSchema::create(description: 'The position (ui.position) within its group as a position string', examples: ['before Neos.Demo:Content.Text', 'end']),
                    NullSchema::create(),
                ),
                properties: Nullable::wrap(NodeTypePropertyList::schema()),
                references: Nullable::wrap(NodeTypeReferenceList::schema()),
                configuration: Nullable::wrap(ObjectSchema::create(
                    description: 'The whole configuration, merged with the super types\', as NodeTypes.yaml declares it. Its labels (label, help message and placeholder values that are translation IDs) are translated to the requested language. In the list only with include=configuration, else null',
                    additionalProperties: true,
                )),
                allowedChildNodeTypes: Nullable::wrap(NodeTypeNameList::schema()),
                tetheredChildNodes: Nullable::wrap(NodeTypeTetheredChildNodeList::schema()),
            ),
            additionalProperties: false,
            required: ['name', 'label', 'icon', 'isAbstract', 'isFinal', 'superTypes', 'group', 'position', 'properties', 'references', 'configuration', 'allowedChildNodeTypes', 'tetheredChildNodes'],
        );
    }

    private static function properties(Core\NodeType\NodeType $nodeType, Labels $labels): NodeTypePropertyList
    {
        $properties = [];
        foreach ($nodeType->getProperties() as $name => $configuration) {
            // internal plumbing like _hidden, not content
            if (str_starts_with((string)$name, '_')) {
                continue;
            }
            $properties[] = NodeTypeProperty::from((string)$name, is_array($configuration) ? $configuration : [], $labels);
        }
        return new NodeTypePropertyList(...$properties);
    }

    private static function references(Core\NodeType\NodeType $nodeType, Labels $labels): NodeTypeReferenceList
    {
        $references = [];
        foreach ($nodeType->getReferences() as $name => $configuration) {
            $references[] = NodeTypeReference::from((string)$name, is_array($configuration) ? $configuration : [], $labels);
        }
        return new NodeTypeReferenceList(...$references);
    }

    /**
     * The node types a node can be created with: neither abstract nor root, sorted by name
     *
     * @return list<Core\NodeType\NodeType>
     */
    public static function childNodeTypeCandidates(Core\NodeType\NodeTypeManager $nodeTypeManager): array
    {
        $candidates = array_filter(
            $nodeTypeManager->getNodeTypes(false),
            static fn (Core\NodeType\NodeType $candidate) => !$candidate->isOfType(Core\NodeType\NodeTypeName::ROOT_NODE_TYPE_NAME),
        );
        ksort($candidates);
        return array_values($candidates);
    }

    /**
     * @param list<Core\NodeType\NodeType> $candidates
     */
    private static function allowedChildNodeTypes(Core\NodeType\NodeType $nodeType, array $candidates): NodeTypeNameList
    {
        return NodeTypeNameList::from(...array_filter(
            $candidates,
            static fn (Core\NodeType\NodeType $candidate) => $nodeType->allowsChildNodeType($candidate),
        ));
    }

    /**
     * @param list<Core\NodeType\NodeType> $candidates
     */
    private static function tetheredChildNodes(Core\NodeType\NodeType $nodeType, Core\NodeType\NodeTypeManager $nodeTypeManager, array $candidates): NodeTypeTetheredChildNodeList
    {
        return new NodeTypeTetheredChildNodeList(...$nodeType->tetheredNodeTypeDefinitions->map(
            static fn (Core\NodeType\TetheredNodeTypeDefinition $definition) => NodeTypeTetheredChildNode::from($nodeType, $definition, $nodeTypeManager, $candidates),
        ));
    }

    /**
     * @param array<mixed> $configuration
     * @return array<mixed>
     */
    private static function translateLabels(array $configuration, Labels $labels): array
    {
        foreach ($configuration as $key => $value) {
            if (is_array($value)) {
                $configuration[$key] = self::translateLabels($value, $labels);
            } elseif (is_string($value) && in_array($key, self::LABEL_KEYS, true)) {
                $configuration[$key] = $labels->label($value) ?? $value;
            }
        }
        return $configuration;
    }
}
