<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The tethered child nodes a node type declares
 *
 * @implements \IteratorAggregate<NodeTypeTetheredChildNode>
 */
final readonly class NodeTypeTetheredChildNodeList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<NodeTypeTetheredChildNode>
     */
    public array $tetheredChildNodes;

    public function __construct(NodeTypeTetheredChildNode ...$tetheredChildNodes)
    {
        $this->tetheredChildNodes = array_values($tetheredChildNodes);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->tetheredChildNodes;
    }
}
