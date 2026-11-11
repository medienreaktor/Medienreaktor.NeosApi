<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\ContentRepository\Core;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A child node a node type declares (childNodes), created and removed with its parent, e.g. a page's main content
 * collection
 */
final readonly class NodeTypeTetheredChildNode implements ProvidesSchema
{
    /**
     * @param string $name the node name of the child, the key in the childNodes configuration, e.g. main
     * @param NodeTypeNameList $allowedChildNodeTypes the node types nodes may have below the child: its own node type's
     *     constraints merged with the ones the parent declares for it (childNodes.<name>.constraints)
     */
    public function __construct(
        public string $name,
        public NodeTypeName $nodeType,
        public NodeTypeNameList $allowedChildNodeTypes,
    ) {
    }

    /**
     * @param list<Core\NodeType\NodeType> $candidates
     */
    public static function from(
        Core\NodeType\NodeType $parentNodeType,
        Core\NodeType\TetheredNodeTypeDefinition $definition,
        Core\NodeType\NodeTypeManager $nodeTypeManager,
        array $candidates,
    ): self {
        return new self(
            $definition->name->value,
            NodeTypeName::fromString($definition->nodeTypeName->value),
            NodeTypeNameList::from(...array_filter(
                $candidates,
                static fn (Core\NodeType\NodeType $candidate) => $nodeTypeManager->isNodeTypeAllowedAsChildToTetheredNode(
                    $parentNodeType->name,
                    $definition->name,
                    $candidate->name,
                ),
            )),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
