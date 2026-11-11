<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The node types of a content repository
 *
 * @implements \IteratorAggregate<NodeType>
 */
final readonly class NodeTypeList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<NodeType>
     */
    public array $nodeTypes;

    public function __construct(NodeType ...$nodeTypes)
    {
        $this->nodeTypes = array_values($nodeTypes);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->nodeTypes;
    }
}
