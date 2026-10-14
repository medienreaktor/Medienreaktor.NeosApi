<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Parameter;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The node types of filterByNodeType (and hasChildrenOfNodeType), in the filter string syntax of Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria
 */
final readonly class NodeTypeCriteria implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * @throws \InvalidArgumentException if the syntax is invalid, e.g. an empty node type name in "!" or "Neos.Neos:Document,"
     */
    public function toNodeTypeCriteria(): NodeType\NodeTypeCriteria
    {
        return NodeType\NodeTypeCriteria::fromFilterString($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'Only nodes of these node types or ones inheriting from them, comma-separated, a ! in front excludes a type and the ones inheriting from it',
            examples: ['Neos.Neos:Document,!Neos.Neos:Shortcut'],
            minLength: 1,
        );
    }
}
