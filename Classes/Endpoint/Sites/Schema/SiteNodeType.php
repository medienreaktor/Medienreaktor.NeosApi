<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\Api\Endpoint\NodeTypes\Schema\NodeTypeName;
use Neos\Api\Infrastructure\I18n\Labels;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A node type a site node can have
 */
final readonly class SiteNodeType implements ProvidesSchema
{
    /**
     * @param string $label the label of the node type in the requested language, its name if it has none
     */
    public function __construct(
        public NodeTypeName $name,
        public string $label,
    ) {
    }

    public static function from(NodeType $nodeType, Labels $labels): self
    {
        return new self(
            NodeTypeName::fromString($nodeType->name->value),
            $labels->label($nodeType->getLabel()) ?? $nodeType->name->value,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
