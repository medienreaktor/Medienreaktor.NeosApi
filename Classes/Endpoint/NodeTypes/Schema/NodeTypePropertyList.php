<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The properties a node type declares
 *
 * @implements \IteratorAggregate<NodeTypeProperty>
 */
final readonly class NodeTypePropertyList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<NodeTypeProperty>
     */
    public array $properties;

    public function __construct(NodeTypeProperty ...$properties)
    {
        $this->properties = array_values($properties);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->properties;
    }
}
