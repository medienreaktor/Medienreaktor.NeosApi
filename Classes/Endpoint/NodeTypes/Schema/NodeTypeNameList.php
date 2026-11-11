<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\ContentRepository\Core;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Names of content repository node types
 *
 * @implements \IteratorAggregate<NodeTypeName>
 */
final readonly class NodeTypeNameList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<NodeTypeName>
     */
    public array $names;

    public function __construct(NodeTypeName ...$names)
    {
        $this->names = array_values($names);
    }

    public static function from(Core\NodeType\NodeType ...$nodeTypes): self
    {
        return new self(...array_map(
            static fn (Core\NodeType\NodeType $nodeType) => NodeTypeName::fromString($nodeType->name->value),
            array_values($nodeTypes),
        ));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->names;
    }
}
